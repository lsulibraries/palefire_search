<?php

namespace Drupal\palefire_search;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * Searches the journals the Libraries can read online, with each one's years.
 *
 * The same service and proxy as Discovery: its publication search lists the
 * titles in the Libraries' holdings, and each title's packages with the years
 * they cover. Everything vendor-specific stays here, so when the holdings move
 * to Alma only this class changes and the rows keep their shape.
 *
 * Also reads the journal Discovery offers beside a search whose words are a
 * journal's name (exactMatch()), so that card needs no request of its own.
 */
class JournalSearch {

  /**
   * The furthest record the service returns, however many it found.
   */
  private const MAX_START = 251;

  /**
   * The kinds of title searched, by filter name, as the service names them.
   *
   * The service also lists books, which the Catalog and Discovery cover. These
   * are the homepage journal search's kinds.
   */
  private const TYPES = [
    'journal' => 'journal',
    'newspaper' => 'newspaper',
    'newsletter' => 'newsletter',
    'report' => 'report',
  ];

  /**
   * What each kind is called on its row.
   */
  private const TYPE_LABELS = [
    'journal' => 'Journal',
    'newspaper' => 'Newspaper',
    'newsletter' => 'Newsletter',
    'report' => 'Report',
  ];

  /**
   * How many packages a row lists before "and more".
   */
  public const COVERAGE_SHOWN = 3;

  /**
   * The service's end date for coverage that runs to today.
   */
  private const PRESENT = '9999';

  public function __construct(
    private readonly ClientInterface $client,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * One page of titles.
   *
   * @param string $terms
   *   The search, already in the service's syntax.
   * @param array $filters
   *   refine: the kinds chosen, by filter name. None means every kind.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   *
   * @return array
   *   total, reachable (how many can be paged to), failed, and items.
   */
  public function search(string $terms, array $filters, int $page, int $limit): array {
    $page = min($page, (int) floor((self::MAX_START - 1) / $limit));
    $types = array_values(array_intersect_key(self::TYPES, array_flip($filters['refine'] ?? []))) ?: array_values(self::TYPES);

    $parameters = [
      // : , ( and ) are the service's syntax, so a title's own are escaped.
      'query-1' => preg_replace('/([:,()])/', '\\\\$1', $terms),
      'sort' => 'relevance',
      'includefacets' => 'n',
      'view' => 'detailed',
      'resultsperpage' => $limit,
      'pagenumber' => $page + 1,
      'highlight' => 'n',
      'facetfilter' => '1,' . implode(',', array_map(static fn(string $type): string => 'TypePublicationPubD:' . $type, $types)),
    ];
    $json = $this->fetch('/edsapi/publication/search?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));

    $total = $json['SearchResult']['Statistics']['TotalHits'] ?? NULL;
    if ($total === NULL) {
      $this->loggerFactory->get('palefire_search')->error('Journals search failed: @message', ['@message' => (string) ($json['DetailedErrorDescription'] ?? 'no results in the response')]);
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }

    return [
      'total' => (int) $total,
      'reachable' => min((int) $total, (int) (floor((self::MAX_START - 1) / $limit) + 1) * $limit),
      'failed' => FALSE,
      'items' => array_values(array_filter(array_map(self::row(...), $json['SearchResult']['Data']['Records'] ?? []))),
    ];
  }

  /**
   * The journal Discovery offers beside its results, when its name is the
   * search itself, or NULL.
   *
   * The service offers one for a near match too ("climate change" brings
   * "WIREs Climate Change"), which would only be in the way of a topic search.
   *
   * @param array $json
   *   Discovery's whole answer to a search asked with relatedcontent=emp.
   * @param string $terms
   *   What was searched.
   */
  public static function exactMatch(array $json, string $terms): ?array {
    foreach ($json['SearchResult']['RelatedContent']['RelatedPublications'] ?? [] as $related) {
      if (($related['Type'] ?? '') !== 'emp') {
        continue;
      }
      foreach ($related['PublicationRecords'] ?? [] as $record) {
        $row = self::row($record);
        if ($row && $row['coverage'] && self::sameTitle($row['title'], $terms)) {
          return $row;
        }
      }
    }
    return NULL;
  }

  /**
   * A title as a row: the module's usual keys, plus coverage, its packages
   * best first ([name, url, ranges: [start, end], delay, delay_unit]), and
   * coverage_more, how many more there are than are listed.
   */
  public static function row(array $record): ?array {
    $details = [];
    foreach ($record['Items'] ?? [] as $detail) {
      $details[$detail['Name'] ?? ''][] = ResultText::clean($detail['Data'] ?? '');
    }
    $entity = $record['RecordInfo']['BibRecord']['BibEntity'] ?? [];
    $title = $details['Title'][0] ?? ResultText::clean((string) ($entity['Titles'][0]['TitleFull'] ?? ''));
    if ($title === '') {
      return NULL;
    }

    $coverage = self::coverage($record['FullTextHoldings'] ?? []);
    $type = mb_strtolower((string) ($record['Header']['ResourceType'] ?? ''));
    return [
      'title' => $title,
      // The service's page for the title lists every package, and searches
      // inside it.
      'url' => ResultText::url(self::proxied((string) ($record['PLink'] ?? ''))),
      'summary' => '',
      'type' => self::TYPE_LABELS[$type] ?? ucfirst($type),
      'meta' => implode(' · ', array_filter([self::issns($entity), $details['Subject'][0] ?? ''])),
      'coverage' => array_slice($coverage, 0, self::COVERAGE_SHOWN),
      'coverage_more' => max(0, count($coverage) - self::COVERAGE_SHOWN),
    ];
  }

  /**
   * Whether a title and a search name the same thing: case, punctuation, "and"
   * for "&" and a leading "The" aside.
   */
  public static function sameTitle(string $title, string $terms): bool {
    $plain = static function (string $text): string {
      $text = mb_strtolower(str_replace('&', ' and ', $text));
      $text = preg_replace('/^\W*the\s+/u', '', $text) ?? $text;
      return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
    };
    $wanted = $plain($terms);
    return $wanted !== '' && $wanted === $plain($title);
  }

  /**
   * The title's ISSNs, print first: "ISSN 0028-0836, 1476-4687 online".
   */
  private static function issns(array $entity): string {
    $issns = [];
    foreach ($entity['Identifiers'] ?? [] as $identifier) {
      $value = (string) ($identifier['Value'] ?? '');
      if (preg_match('/^(\d{4})-?(\d{3}[\dXx])$/', $value, $match) && in_array($identifier['Type'] ?? '', ['issn-print', 'issn-online'], TRUE)) {
        $issns[$identifier['Type']] = $match[1] . '-' . strtoupper($match[2]);
      }
    }
    if (!$issns) {
      return '';
    }
    if (count($issns) === 1) {
      return 'ISSN ' . reset($issns);
    }
    return 'ISSN ' . $issns['issn-print'] . ', ' . $issns['issn-online'] . ' online';
  }

  /**
   * The packages carrying a title, each once, best first: running to today,
   * then reaching furthest back, then with no delay.
   */
  private static function coverage(array $holdings): array {
    $packages = [];
    foreach ($holdings as $holding) {
      $name = ResultText::clean((string) ($holding['Name'] ?? ''));
      $url = ResultText::url(self::proxied((string) ($holding['URL'] ?? '')));
      if ($name === '' || $url === '#') {
        continue;
      }
      $ranges = [];
      foreach ($holding['CoverageDates'] ?? [] as $dates) {
        $start = substr((string) ($dates['StartDate'] ?? ''), 0, 4);
        $end = substr((string) ($dates['EndDate'] ?? ''), 0, 4);
        if (preg_match('/^\d{4}$/', $start)) {
          $ranges[] = ['start' => (int) $start, 'end' => $end === self::PRESENT || !preg_match('/^\d{4}$/', $end) ? NULL : (int) $end];
        }
      }
      usort($ranges, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
      $delay = (int) ($holding['Embargo'] ?? 0);
      $packages[$name . '|' . $url] ??= [
        'name' => $name,
        'url' => $url,
        'ranges' => $ranges,
        'delay' => $delay > 0 ? $delay : NULL,
        'delay_unit' => $delay > 0 ? mb_strtolower((string) ($holding['EmbargoUnit'] ?? 'year')) : NULL,
      ];
    }

    $packages = array_values($packages);
    usort($packages, static function (array $a, array $b): int {
      $current = static fn(array $package): int => ($package['ranges'] && end($package['ranges'])['end'] === NULL) ? 0 : 1;
      $first = static fn(array $package): int => $package['ranges'][0]['start'] ?? PHP_INT_MAX;
      return [$current($a), $first($a), $a['delay'] ? 1 : 0] <=> [$current($b), $first($b), $b['delay'] ? 1 : 0];
    });
    return $packages;
  }

  /**
   * Off campus, a link has to pass through the proxy.
   */
  private static function proxied(string $url): string {
    return $url !== '' && !str_contains($url, 'libezp.lib.lsu.edu') ? 'https://libezp.lib.lsu.edu/login?url=' . $url : $url;
  }

  /**
   * The proxy's answer, or NULL if it didn't answer. The service's own errors
   * come back as JSON with an error status, so they're read, not thrown.
   */
  private function fetch(string $path): ?array {
    $url = Url::fromUri('internal:/api-proxy/eds_api_proxy', [
      'query' => ['_api_proxy_uri' => $path],
      'absolute' => TRUE,
    ])->toString(TRUE)->getGeneratedUrl();
    try {
      $response = $this->client->request('GET', $url, ['timeout' => 25, 'http_errors' => FALSE]);
      return json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Throwable $error) {
      $this->loggerFactory->get('palefire_search')->error('Journals search failed: @message', ['@message' => $error->getMessage()]);
      return NULL;
    }
  }

}

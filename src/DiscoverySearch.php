<?php

namespace Drupal\palefire_search;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * Searches Discovery directly, through the site's own proxy.
 *
 * The eds module keeps the credentials and the proxy. Its View can't pass
 * limiters, so this asks the proxy the same way the View does. Everything
 * vendor-specific stays here: when Discovery moves to Alma, replace this class
 * and the rest of the module keeps its filter names and row shape.
 */
class DiscoverySearch {

  /**
   * The furthest record the service returns, however many it found.
   */
  private const MAX_START = 291;

  /**
   * The "Limit to" filters, by the service's limiter IDs.
   */
  private const LIMITERS = [
    'full_text' => 'FT',
    'peer_reviewed' => 'RV',
    'in_collection' => 'FT1',
    'epub' => 'FM30',
  ];

  /**
   * The "Language" filters, by the service's language facet values.
   *
   * The facet, not the language limiter: the limiter only reads some of the
   * databases, so it finds a fraction of what the facet counts. Some languages
   * come under more than one name, and a filter takes them all.
   */
  private const LANGUAGES = [
    'language_english' => ['english', 'eng'],
    'language_french' => ['french', 'francais'],
    'language_german' => ['german'],
    'language_spanish' => ['spanish', 'spanish; castilian'],
    'language_latin' => ['latin'],
    'language_italian' => ['italian'],
    'language_dutch' => ['dutch', 'dutch; flemish', 'dutch/flemish'],
    'language_portuguese' => ['portuguese'],
    'language_russian' => ['russian'],
    'language_chinese' => ['chinese'],
    'language_japanese' => ['japanese'],
    'language_arabic' => ['arabic'],
  ];

  /**
   * The "Type" filters, by the service's source type names.
   */
  private const TYPES = [
    'journals' => 'Academic Journals',
    'books' => 'Books',
    'ebooks' => 'eBooks',
    'magazines' => 'Magazines',
    'news' => 'News',
    'reviews' => 'Reviews',
    'trade' => 'Trade Publications',
    'reports' => 'Reports',
    'biographies' => 'Biographies',
    'primary_sources' => 'Primary Source Documents',
    'dissertations' => 'Dissertations',
    'conferences' => 'Conference Materials',
  ];

  /**
   * What a record's publication type means to someone reading the list.
   */
  private const TYPE_LABELS = [
    'Academic Journal' => 'Journal article',
    'Periodical' => 'Magazine article',
    'News' => 'News article',
    'eBook' => 'E-book',
    'Reference' => 'Reference entry',
  ];

  /**
   * Type and language counts from the latest search, by filter name.
   */
  private array $counts = [];

  public function __construct(
    private readonly ClientInterface $client,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * One page of results.
   *
   * @param string $terms
   *   The search, already in the service's syntax.
   * @param array $filters
   *   refine: filter names; from, to: years.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   * @param string $sort
   *   The service's sort name.
   *
   * @return array
   *   total, reachable (how many can be paged to), failed, and items.
   */
  public function search(string $terms, array $filters, int $page, int $limit, string $sort): array {
    $this->counts = [];
    $page = min($page, (int) floor((self::MAX_START - 1) / $limit));

    $json = $this->fetch($this->address($terms, $filters, $page, $limit, $sort, $page === 0));

    // The service only reaches so far past what it has already read for a
    // search. Reading as far as it allows opens the page asked for.
    if (preg_match('/Max start request number is\s*:\s*(\d+)/', (string) ($json['DetailedErrorDescription'] ?? ''), $match)) {
      $this->fetch($this->address($terms, $filters, intdiv((int) $match[1] - 1, $limit), $limit, $sort));
      $json = $this->fetch($this->address($terms, $filters, $page, $limit, $sort));
    }

    $total = $json['SearchResult']['Statistics']['TotalHits'] ?? NULL;
    if ($total === NULL) {
      $this->loggerFactory->get('palefire_search')->error('Discovery search failed: @message', ['@message' => (string) ($json['DetailedErrorDescription'] ?? 'no results in the response')]);
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }

    $this->counts = $this->typeCounts($json['SearchResult']['AvailableFacets'] ?? []);
    // With a language chosen, the service counts the others within it, not
    // what they'd add, so a 0 would turn off one that widens the results.
    if (array_intersect_key(self::LANGUAGES, array_flip($filters['refine'] ?? []))) {
      $this->counts = array_diff_key($this->counts, self::LANGUAGES);
    }
    return [
      'total' => (int) $total,
      'reachable' => min((int) $total, (int) (floor((self::MAX_START - 1) / $limit) + 1) * $limit),
      'failed' => FALSE,
      'items' => array_values(array_filter(array_map($this->record(...), $json['SearchResult']['Data']['Records'] ?? []))),
      // A search for a journal by name gets that journal first, with its years.
      'journal' => $page === 0 ? JournalSearch::exactMatch($json, $terms) : NULL,
    ];
  }

  /**
   * A response from the proxy, or NULL if it didn't answer. The service's own
   * errors come back as JSON with an error status, so they're read, not thrown.
   */
  private function fetch(string $url): ?array {
    try {
      $response = $this->client->request('GET', $url, ['timeout' => 25, 'http_errors' => FALSE]);
      return json_decode((string) $response->getBody(), TRUE);
    }
    catch (\Throwable $error) {
      $this->loggerFactory->get('palefire_search')->error('Discovery search failed: @message', ['@message' => $error->getMessage()]);
      return NULL;
    }
  }

  /**
   * Type and language counts from the latest search, or nothing if it failed.
   *
   * Choosing a type doesn't change these, so each says what it would add.
   */
  public function counts(): array {
    return $this->counts;
  }

  /**
   * The proxy's address for a search.
   *
   * The proxy keeps only the last of a repeated parameter, so limiters are
   * numbered and all the types go in one facet filter: values of a type widen
   * the results, and each limiter narrows them. $journal asks for the journal
   * the search may name (JournalSearch::exactMatch()).
   */
  private function address(string $terms, array $filters, int $page, int $limit, string $sort, bool $journal = FALSE): string {
    $parameters = [
      // A bare comma would end the search there: it separates a field from
      // its value.
      'query-1' => str_replace(',', '\\,', $terms),
      'sort' => $sort,
      'includefacets' => 'y',
      'searchmode' => 'bool',
      'view' => 'detailed',
      'resultsperpage' => $limit,
      'pagenumber' => $page + 1,
      'highlight' => 'n',
    ];
    if ($journal) {
      $parameters['relatedcontent'] = 'emp';
    }

    $limiters = array_map(
      static fn(string $id): string => $id . ':y',
      array_values(array_intersect_key(self::LIMITERS, array_flip($filters['refine'] ?? []))),
    );
    if (isset($filters['from']) || isset($filters['to'])) {
      $limiters[] = sprintf('DT1:%04d-01/%04d-12', $filters['from'] ?? 1000, $filters['to'] ?? 2100);
    }
    foreach ($limiters as $index => $limiter) {
      $parameters['limiter-' . ($index + 1)] = $limiter;
    }

    // One facet filter: values of one facet widen the results, and each facet
    // narrows them, so Books and French is books in French.
    $chosen = array_flip($filters['refine'] ?? []);
    $values = [
      ...array_map(static fn(string $type): string => 'SourceType:' . $type, array_values(array_intersect_key(self::TYPES, $chosen))),
      ...array_map(static fn(string $language): string => 'Language:' . $language, array_merge(...array_values(array_intersect_key(self::LANGUAGES, $chosen)))),
    ];
    if ($values) {
      $parameters['facetfilter'] = '1,' . implode(',', $values);
    }

    return Url::fromUri('internal:/api-proxy/eds_api_proxy', [
      'query' => ['_api_proxy_uri' => '/edsapi/rest/search?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)],
      'absolute' => TRUE,
    ])->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * The service's source type and language counts, by filter name. Without a
   * language facet, the languages have no number rather than a 0 that would
   * turn them off.
   */
  private function typeCounts(array $facets): array {
    $found = [];
    foreach ($facets as $facet) {
      $id = $facet['Id'] ?? '';
      if ($id === 'SourceType' || $id === 'Language') {
        foreach ($facet['AvailableFacetValues'] ?? [] as $value) {
          $found[$id][$value['Value'] ?? ''] = (int) ($value['Count'] ?? 0);
        }
      }
    }
    $counts = array_map(static fn(string $type): int => $found['SourceType'][$type] ?? 0, self::TYPES);
    if (isset($found['Language'])) {
      foreach (self::LANGUAGES as $name => $names) {
        $counts[$name] = array_sum(array_intersect_key($found['Language'], array_flip($names)));
      }
    }
    return $counts;
  }

  /**
   * A record as a row. Its text is cleaned by the caller.
   */
  private function record(array $record): ?array {
    $details = [];
    foreach ($record['Items'] ?? [] as $detail) {
      $details[$detail['Name'] ?? ''][] = $detail['Data'] ?? '';
    }
    // Some records, such as NewsBank's, have no items, only their bibliography.
    $title = $details['Title'][0] ?? (string) ($record['RecordInfo']['BibRecord']['BibEntity']['Titles'][0]['TitleFull'] ?? '');
    if ($title === '') {
      return NULL;
    }
    $type = (string) ($record['Header']['PubType'] ?? '');
    return [
      'title' => $title,
      'url' => ResultText::url($this->proxied((string) ($record['PLink'] ?? ''))),
      'summary' => $details['Abstract'][0] ?? '',
      'type' => self::TYPE_LABELS[$type] ?? $type,
      'meta' => implode(' · ', array_filter([
        $this->authors($record, $details),
        $this->publication($record, $details, $title),
      ])),
      'full_text_url' => $this->fullText($record),
      'image_url' => $this->cover($record),
    ];
  }

  /**
   * Who wrote it: up to three names, each once.
   */
  private function authors(array $record, array $details): string {
    $names = [];
    foreach ($record['RecordInfo']['BibRecord']['BibRelationships']['HasContributorRelationships'] ?? [] as $contributor) {
      $names[] = ResultText::clean((string) ($contributor['PersonEntity']['Name']['NameFull'] ?? ''));
    }
    $names = array_values(array_unique(array_filter($names)));
    if (!$names) {
      return ResultText::clean($details['Author'][0] ?? '');
    }
    return implode('; ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' et al.' : '');
  }

  /**
   * Where and when it was published. A book is its own publication, so a book
   * shows only its year.
   */
  private function publication(array $record, array $details, string $title): string {
    $entity = $record['RecordInfo']['BibRecord']['BibRelationships']['IsPartOfRelationships'][0]['BibEntity'] ?? [];
    if (!$entity) {
      return ResultText::clean($details['TitleSource'][0] ?? '');
    }
    $source = ResultText::clean((string) ($entity['Titles'][0]['TitleFull'] ?? ''));
    $same = static fn(string $text): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
    if ($same($source) === $same(ResultText::clean($title))) {
      $source = '';
    }
    return implode(', ', array_filter([$source, (string) ($entity['Dates'][0]['Y'] ?? '')]));
  }

  /**
   * A link to the full text: where the library has it, or the record if the
   * service holds it itself. NULL if neither says it's online.
   */
  private function fullText(array $record): ?string {
    $full_text = $record['FullText'] ?? [];
    foreach ($full_text['CustomLinks'] ?? [] as $link) {
      if (($link['Category'] ?? '') === 'fullText' && !empty($link['Url'])) {
        return $this->linkOrNull((string) $link['Url']);
      }
    }
    $types = array_column($full_text['Links'] ?? [], 'Type');
    $online = (string) ($full_text['Text']['Availability'] ?? '') === '1'
      || array_filter($types, static fn(string $type): bool => $type === 'pdflink' || str_starts_with($type, 'ebook-'));
    return $online ? $this->linkOrNull((string) ($record['PLink'] ?? '')) : NULL;
  }

  /**
   * A safe, proxied link, or NULL if there's nothing to link to.
   */
  private function linkOrNull(string $url): ?string {
    $url = ResultText::url($this->proxied($url));
    return $url === '#' ? NULL : $url;
  }

  /**
   * Off campus, a link has to pass through the proxy.
   */
  private function proxied(string $url): string {
    return $url !== '' && !str_contains($url, 'libezp.lib.lsu.edu') ? 'https://libezp.lib.lsu.edu/login?url=' . $url : $url;
  }

  /**
   * A record's cover: the larger size if there's a choice.
   */
  private function cover(array $record): ?string {
    $covers = [];
    foreach ($record['ImageInfo'] ?? [] as $image) {
      $covers[$image['Size'] ?? ''] = (string) ($image['Target'] ?? '');
    }
    return ResultText::imageUrl($covers['medium'] ?? $covers['thumb'] ?? '');
  }

}

<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Searches the Catalog through its public results feed and results page.
 *
 * The feed needs no login, and always answers with up to 300 records and no
 * total, so each search is read once, kept for a while, and paged here. The
 * results page has the real total and the filters' counts. It's written for
 * people and could change, so without it the filters still work, with no
 * numbers. Everything vendor-specific stays here: when the Catalog moves to
 * Alma, replace this class and the rest of the module keeps its filter names
 * and row shape.
 */
class CatalogSearch {

  /**
   * The feed's address, up to the search.
   */
  private const FEED = 'https://lsu.ent.sirsi.net/client/rss/hitlist/lsu/qu=';

  /**
   * The results page's address, up to the search.
   */
  private const PAGE = 'https://lsu.ent.sirsi.net/client/en_US/lsu/search/results?qu=';

  /**
   * A record's page, up to its encoded ID.
   */
  private const RECORD = 'https://lsu.ent.sirsi.net/client/en_US/lsu/search/detailnonmodal/';

  /**
   * The most records the feed returns, however many it found.
   */
  private const MAX_RECORDS = 300;

  /**
   * How long a search is kept, in seconds.
   */
  private const KEEP = 3600;

  /**
   * The filters: the service's filter, its name, the value and its name.
   *
   * The feed's address holds these in its path, so no name can have a slash,
   * and the service only reads the value: Archive stands for
   * Archive/Manuscript.
   */
  private const FILTERS = [
    'type_book' => ['ITYPE', 'Type', '21:BOOK', 'Book'],
    'type_archive' => ['ITYPE', 'Type', '21:ARCH-MSS', 'Archive'],
    'type_online' => ['ITYPE', 'Type', '21:ONLINE', 'Electronic resource'],
    'type_microform' => ['ITYPE', 'Type', '21:MICROFORM', 'Microform'],
    'type_av' => ['ITYPE', 'Type', '21:AV', 'Audio-visual'],
    'type_journal' => ['ITYPE', 'Type', '21:SERIAL', 'Print Journal'],
    'type_unbound' => ['ITYPE', 'Type', '21:SERIAL-UB', 'Print Journal (Unbound)'],
    'type_score' => ['ITYPE', 'Type', '21:SCORE', 'Music Score'],
    'type_map' => ['ITYPE', 'Type', '21:MAP', 'Map'],
    'type_newspaper' => ['ITYPE', 'Type', '21:NEWSPAPER', 'Newspaper'],
    'type_graphic' => ['ITYPE', 'Type', '21:GRAPHIC', 'Posters'],
    'library_main' => ['LIBRARY', 'Library', '21:LSU-MAIN', 'LSU Library'],
    'library_special' => ['LIBRARY', 'Library', '21:SPEC_COLL', 'Special Collections'],
    'library_documents' => ['LIBRARY', 'Library', '21:DOCS_MICRO', 'Government Documents'],
    'library_music' => ['LIBRARY', 'Library', '21:MUSIC', 'Carter Music Resources Center'],
    'library_maps' => ['LIBRARY', 'Library', '21:CIC', 'Cartographic Information Center'],
    'library_veterinary' => ['LIBRARY', 'Library', '21:VETMED', 'Veterinary Medicine Library'],
    'language_english' => ['LANGUAGE', 'Language', 'ENG', 'English'],
    'language_french' => ['LANGUAGE', 'Language', 'FRE', 'French'],
    'language_german' => ['LANGUAGE', 'Language', 'GER', 'German'],
    'language_spanish' => ['LANGUAGE', 'Language', 'SPA', 'Spanish'],
    'language_latin' => ['LANGUAGE', 'Language', 'LAT', 'Latin'],
    'language_italian' => ['LANGUAGE', 'Language', 'ITA', 'Italian'],
    'language_dutch' => ['LANGUAGE', 'Language', 'DUT', 'Dutch'],
    'language_portuguese' => ['LANGUAGE', 'Language', 'POR', 'Portuguese'],
    'language_russian' => ['LANGUAGE', 'Language', 'RUS', 'Russian'],
    'language_chinese' => ['LANGUAGE', 'Language', 'CHI', 'Chinese'],
    'language_japanese' => ['LANGUAGE', 'Language', 'JPN', 'Japanese'],
    'language_arabic' => ['LANGUAGE', 'Language', 'ARA', 'Arabic'],
  ];

  /**
   * Filter counts from the latest full search, by filter name.
   */
  private array $counts = [];

  public function __construct(
    private readonly ClientInterface $client,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * One page of results.
   *
   * @param string $terms
   *   The search, with no operators.
   * @param array $filters
   *   refine: filter names, one to a filter; from, to: years; full: TRUE to also
   *   read the real total and the filters' counts.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   *
   * @return array
   *   total, reachable, capped (TRUE when there may be more than the total),
   *   failed, and items.
   */
  public function search(string $terms, array $filters, int $page, int $limit): array {
    $this->counts = [];
    // These would break the feed's address, which holds the search in its path.
    $terms = trim(preg_replace('#[/\\\\?\#%$]+#u', ' ', $terms) ?? '');
    if ($terms === '') {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }
    $address = rawurlencode($terms) . $this->qualifiers($filters);

    $records = $this->records($address);
    if ($records === NULL) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }
    $found = count($records);

    $facts = !empty($filters['full']) ? $this->facts($address) : NULL;
    if ($facts) {
      $this->counts = $this->optionCounts($facts['counts'], $filters);
    }
    $total = max($found, (int) ($facts['total'] ?? 0));

    $page = min($page, (int) floor(max(0, $found - 1) / $limit));
    return [
      'total' => $total,
      'reachable' => $found,
      'capped' => !isset($facts['total']) && $found >= self::MAX_RECORDS,
      'failed' => FALSE,
      'items' => array_slice($records, $page * $limit, $limit),
    ];
  }

  /**
   * Filter counts from the latest full search, by filter name.
   *
   * An option with no number has no count to give, and 0 means it would find
   * nothing.
   */
  public function counts(): array {
    return $this->counts;
  }

  /**
   * The filters as the end of an address, one to each of the service's filters.
   */
  private function qualifiers(array $filters): string {
    $parts = [];
    foreach ($filters['refine'] ?? [] as $option) {
      if (isset(self::FILTERS[$option])) {
        $parts[self::FILTERS[$option][0]] = self::FILTERS[$option];
      }
    }
    if (isset($filters['from']) || isset($filters['to'])) {
      // The service wants both ends. Its records go back no further than this.
      $range = ($filters['from'] ?? 1000) . '-' . ($filters['to'] ?? date('Y'));
      $parts['PUBDATE'] = ['PUBDATE', 'Publication Date', $range, $range];
    }
    $address = '';
    foreach ($parts as $part) {
      $address .= '&qf=' . rawurlencode(implode("\t", $part));
    }
    return $address;
  }

  /**
   * Every record the feed gives for a search, or NULL if it couldn't be read.
   */
  private function records(string $address): ?array {
    $key = 'palefire_search:catalog:' . hash('sha256', $address);
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }

    $body = $this->fetch(self::FEED . $address);
    if ($body === NULL) {
      return NULL;
    }
    // The feed is sent as a web page, so its host adds a script after the end,
    // which a strict reader rejects. The feed itself ends at its closing tag.
    $end = strrpos($body, '</feed>');
    $body = $end === FALSE ? $body : substr($body, 0, $end + strlen('</feed>'));

    $previous = libxml_use_internal_errors(TRUE);
    $feed = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($feed === FALSE) {
      $this->loggerFactory->get('palefire_search')->error('Catalog search failed: a feed that could not be read.');
      return NULL;
    }

    $records = [];
    foreach ($feed->entry as $entry) {
      $record = $this->record($entry);
      if ($record) {
        $records[] = $record;
      }
    }
    $this->cache->set($key, $records, time() + self::KEEP);
    return $records;
  }

  /**
   * The results page's total and counts, or NULL if it couldn't be read.
   *
   * @return array|null
   *   total, or NULL if the page doesn't say, and counts by the service's filter
   *   and value, such as ITYPE|21:BOOK.
   */
  private function facts(string $address): ?array {
    $key = 'palefire_search:catalog_facts:' . hash('sha256', $address);
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }

    $body = $this->fetch(self::PAGE . $address);
    if ($body === NULL) {
      return NULL;
    }
    $total = preg_match('#resultsToolbar_num_results">\s*([\d,]+)\s*Results#', $body, $match)
      ? (int) str_replace(',', '', $match[1])
      : NULL;

    // Each option links to the search with that filter added, so its own filter
    // is the last one in the address.
    $counts = [];
    preg_match_all('#href="([^"]*qf=[^"]*)"[^>]*>[^<]*</a></span><div class="navigatorCount">\s*\(([\d,]+)\)#', $body, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $last = substr($href, strrpos($href, 'qf=') + 3);
      $parts = explode("\t", urldecode(strtok($last, '&')));
      if (count($parts) >= 3) {
        $counts[$parts[0] . '|' . $parts[2]] = (int) str_replace(',', '', $match[2]);
      }
    }

    $facts = ['total' => $total, 'counts' => $counts];
    $this->cache->set($key, $facts, time() + self::KEEP);
    return $facts;
  }

  /**
   * The counts by filter name.
   *
   * A filter with a choice made only counts what's in that choice, so its other
   * options have no number. In any other filter, an option the page doesn't
   * list would find nothing.
   */
  private function optionCounts(array $counts, array $filters): array {
    $chosen = [];
    foreach ($filters['refine'] ?? [] as $option) {
      $chosen[self::FILTERS[$option][0] ?? ''] = TRUE;
    }
    $options = [];
    foreach (self::FILTERS as $option => [$filter, , $value]) {
      if (isset($chosen[$filter])) {
        continue;
      }
      $options[$option] = $counts[$filter . '|' . $value] ?? 0;
    }
    return $options;
  }

  /**
   * A page's text, or NULL if it couldn't be fetched.
   */
  private function fetch(string $url): ?string {
    try {
      $response = $this->client->request('GET', $url, [
        'connect_timeout' => 5,
        'timeout' => 15,
        'http_errors' => FALSE,
        'headers' => ['User-Agent' => 'LSU Libraries search'],
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('palefire_search')->error('Catalog search failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    if ($response->getStatusCode() !== 200) {
      $this->loggerFactory->get('palefire_search')->error('Catalog search failed: status @status.', ['@status' => $response->getStatusCode()]);
      return NULL;
    }
    return (string) $response->getBody();
  }

  /**
   * A result row from one entry, or NULL without a title.
   */
  private function record(\SimpleXMLElement $entry): ?array {
    $fields = $this->fields((string) $entry->content);
    $author = ResultText::clean($fields['Author'] ?? '');

    // Titles often end with their author, which the row shows on its own line.
    $title = ResultText::clean((string) $entry->title);
    if ($author !== '') {
      $title = preg_replace('#\s/\s.*$#u', '', $title) ?? $title;
    }
    $title = rtrim($title, " /,:;=");
    if ($title === '') {
      return NULL;
    }

    $id = (string) $entry->id;
    $url = preg_match('#^ent://[\w./:-]+$#', $id)
      ? self::RECORD . str_replace('/', '$002f', $id) . '/one'
      : (string) ($entry->link['href'] ?? '');

    return [
      'title' => $title,
      'url' => ResultText::url($url),
      'summary' => '',
      'meta' => implode(' · ', array_filter([
        $this->withoutFinalPeriod($author),
        $this->withoutFinalPeriod(ResultText::clean($fields['Publication Dates'] ?? $fields['Title Inclusive Dates'] ?? '')),
        ResultText::clean($fields['Call Number'] ?? ''),
      ])),
      'type' => ResultText::clean($fields['Format'] ?? ''),
      'full_text_url' => $this->fullText($fields['Electronic Access'] ?? ''),
    ];
  }

  /**
   * Text without a catalog record's closing period, keeping one that belongs to
   * an initial or a short form: Smith, J. and 1934 Sept. stay as they are.
   */
  private function withoutFinalPeriod(string $text): string {
    return preg_replace('/(?<!\b[A-Z])(?<!\b(?:Jr|Sr))(?<!\b(?:Inc|Ltd|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Oct|Nov|Dec))(?<!Sept)\.$/', '', $text) ?? $text;
  }

  /**
   * An entry's content as its fields: Author&#160;Name<br/>Format&#160;Book.
   */
  private function fields(string $content): array {
    $fields = [];
    foreach (preg_split('#<br\s*/?>#i', $content) ?: [] as $line) {
      $parts = explode('&#160;', $line, 2);
      if (count($parts) === 2) {
        $fields[trim($parts[0])] = $parts[1];
      }
    }
    return $fields;
  }

  /**
   * An online copy's address, or NULL.
   *
   * A link with words before it is something else, such as "Finding aid:".
   */
  private function fullText(string $access): ?string {
    if (!preg_match('#^\s*<a\s+href="(https?://[^"]+)"#i', $access, $match)) {
      return NULL;
    }
    return ResultText::url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

}

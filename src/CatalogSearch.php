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
 *
 * The catalog's web service works in tandem with the feed. The feed chooses and
 * orders the rows, because only it ranks by relevance. The service then says
 * where each row's copies are and whether they're available, which changes too
 * often to keep with the rows. If the feed goes down, the service also answers
 * the search, newest first, because that's the only order it has.
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
   * The web service's address, and the public ID the catalog's own pages use.
   */
  private const SERVICE = 'https://lalu.sirsi.net/lalu_ilsws/rest/';
  private const CLIENT = 'DS_CLIENT';

  /**
   * How long availability is kept, in seconds, and names of places.
   */
  private const HOLDINGS_KEEP = 120;
  private const NAMES_KEEP = 86400;

  /**
   * Seconds to wait. A late section gives up after 20, and the feed and the
   * service may both have to answer within that.
   */
  private const FEED_TIMEOUT = 12;
  private const SERVICE_TIMEOUT = 5;

  /**
   * The place an online copy lives, and the call number it has there.
   */
  private const ONLINE = 'ONLINE';
  private const ONLINE_CALL_NUMBER = 'AVAILABLE ONLINE';

  /**
   * Places that mean a copy can't be had, even when one is its home.
   */
  private const AWAY = [
    'BINDERY', 'CATALOGING', 'CHECKEDOUT', 'DISCARD', 'HOLDS', 'ILL', 'INPROCESS',
    'INSHIPPING', 'INTRANSIT', 'LONGOVRDUE', 'LOST', 'LOST-ASSUM', 'LOST-CLAIM',
    'LOST-PAID', 'MISSING', 'ON-ORDER', 'ONLINE-NLA', 'OVD-RECALL', 'REPAIR',
  ];

  /**
   * Plainer words for the commonest reasons. The rest use the service's own.
   */
  private const REASONS = [
    'CHECKEDOUT' => 'Checked out',
    'HOLDS' => 'On hold',
    'ON-ORDER' => 'On order',
  ];

  /**
   * What a record is, for rows from the service, which only has a code.
   */
  private const KINDS = [
    'BOOK' => 'Book',
    'ER' => 'Electronic Resources',
    'MAP' => 'Map',
    'MICROFORM' => 'Microform',
    'MUSICSNDREC' => 'Sound recording',
    'NOTATEDMUSIC' => 'Music Score',
    'SOUNDDISC' => 'Sound recording',
    'VIDEODISC' => 'Video',
  ];

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
   *   failed, backup (TRUE when the feed was down and the service answered),
   *   and items.
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
      return $this->serviceSearch($terms, $filters, $page, $limit);
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
   * Where each record's copies are, and whether they're available.
   *
   * @param string[] $ids
   *   Record IDs, as digits.
   *
   * @return array|null
   *   Each record's holdings by its ID, leaving out records with none, or NULL
   *   if the service couldn't be reached. See holding().
   */
  public function holdings(array $ids): ?array {
    if (!$ids) {
      return [];
    }
    sort($ids);
    $key = 'palefire_search:catalog_holdings:' . hash('sha256', implode(',', $ids));
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }

    $titles = array_map(static fn(string $id): string => 'titleID=' . $id, $ids);
    $answer = $this->service('standard/lookupTitleInfo', implode('&', $titles) . '&includeItemInfo=true&includeAvailabilityInfo=true&includeMarcHoldings=true&marcEntryFilter=ALL');
    if ($answer === NULL) {
      return NULL;
    }

    $libraries = $this->names('lookupLibraryPolicyList');
    $locations = $this->names('lookupLocationPolicyList');
    $holdings = [];
    foreach ($answer['TitleInfo'] ?? [] as $title) {
      $holding = $this->holding($title, $libraries, $locations);
      if ($holding) {
        $holdings[$holding['id']] = $holding;
      }
    }
    $this->cache->set($key, $holdings, time() + self::HOLDINGS_KEEP);
    return $holdings;
  }

  /**
   * One record's holdings: one entry for each library and shelf.
   *
   * A journal has a call number for every volume, so grouping by call number
   * would list a hundred rows for one shelf.
   *
   * @return array|null
   *   id; state: available, online, out or listed (held, with no copy to check);
   *   place, reason and due for the first entry; more, how many other entries;
   *   url, the record's page; and copies, each [library, shelf, call_number,
   *   call_more, has, available, total, online, reason, due]. NULL with no copies.
   */
  private function holding(array $title, array $libraries, array $locations): ?array {
    $id = (string) ($title['titleID'] ?? '');
    $groups = [];
    $blank = ['calls' => [], 'marc_call' => '', 'has' => [], 'available' => 0, 'total' => 0, 'reason' => '', 'due' => ''];

    foreach ($title['CallInfo'] ?? [] as $call) {
      $library = (string) ($call['libraryID'] ?? '');
      $number = trim((string) ($call['callNumber'] ?? ''));
      foreach ($call['ItemInfo'] ?? [] as $item) {
        $home = (string) ($item['homeLocationID'] ?? '');
        $current = (string) ($item['currentLocationID'] ?? $home);
        $group = $library . '|' . $home;
        $groups[$group] ??= $blank;
        $groups[$group]['total']++;
        if ($number !== '' && $number !== self::ONLINE_CALL_NUMBER) {
          $groups[$group]['calls'][$number] = TRUE;
        }
        if ($current === $home && empty($item['dueDate']) && !in_array($current, self::AWAY, TRUE)) {
          $groups[$group]['available']++;
        }
        elseif ($groups[$group]['reason'] === '') {
          $groups[$group]['reason'] = $this->reason($current, $locations);
          $groups[$group]['due'] = empty($item['dueDate']) ? '' : date('M j, Y', (int) ((int) $item['dueDate'] / 1000));
        }
      }
    }

    // Journals say what's held, and their general call number, for each shelf.
    foreach ($title['MarcHoldingsInfo'] ?? [] as $holding) {
      $library = (string) ($holding['holdingLibraryID'] ?? '');
      $home = '';
      $number = '';
      $has = [];
      foreach ($holding['MarcEntryInfo'] ?? [] as $entry) {
        $text = trim((string) ($entry['text'] ?? ''));
        if ((string) ($entry['entryID'] ?? '') === '852') {
          [$home, $number] = array_map('trim', array_pad(explode('--', $text, 2), 2, ''));
        }
        elseif ((string) ($entry['entryID'] ?? '') === '866' && $text !== '') {
          $has[] = rtrim($text, ' ;');
        }
      }
      if ($home !== '') {
        $group = $library . '|' . $home;
        $groups[$group] ??= $blank;
        $groups[$group]['marc_call'] = $groups[$group]['marc_call'] ?: $number;
        array_push($groups[$group]['has'], ...$has);
      }
    }
    if ($id === '' || !$groups) {
      return NULL;
    }

    $copies = [];
    foreach ($groups as $group => $found) {
      [$library, $home] = explode('|', $group, 2);
      $calls = array_map('strval', array_keys($found['calls']));
      $copies[] = [
        'library' => $libraries[$library] ?? $library,
        'shelf' => $locations[$home] ?? $home,
        'call_number' => $found['marc_call'] ?: ($calls[0] ?? ''),
        'call_more' => $found['marc_call'] ? 0 : max(0, count($calls) - 1),
        'has' => implode('; ', $found['has']),
        'available' => $found['available'],
        'total' => $found['total'],
        'online' => $home === self::ONLINE,
        'reason' => $found['reason'],
        'due' => $found['due'],
      ];
    }
    // What someone can go and get comes first, then where there's most of it.
    usort($copies, fn(array $a, array $b): int => [$this->rank($a), $b['available']] <=> [$this->rank($b), $a['available']]);

    $first = $copies[0];
    return [
      'id' => $id,
      'state' => match ($this->rank($first)) {
        0 => 'available',
        1 => 'online',
        2 => 'out',
        default => 'listed',
      },
      'place' => $first['library'] . ' · ' . $first['shelf'],
      'reason' => $first['reason'],
      'due' => $first['due'],
      'more' => count($copies) - 1,
      'url' => ResultText::url(self::RECORD . 'ent:$002f$002fSD_LSU$002f0$002fSD_LSU:' . $id . '/one'),
      'copies' => $copies,
    ];
  }

  /**
   * An entry's place in the list: available, online, not available, or listed.
   */
  private function rank(array $copy): int {
    return match (TRUE) {
      $copy['available'] > 0 && !$copy['online'] => 0,
      $copy['available'] > 0 => 1,
      $copy['total'] > 0 => 2,
      default => 3,
    };
  }

  /**
   * Why a copy can't be had, from where it is now.
   */
  private function reason(string $current, array $locations): string {
    if (isset(self::REASONS[$current])) {
      return self::REASONS[$current];
    }
    $name = trim(preg_replace('/[.\s]*Due:\s*$/', '', $locations[$current] ?? $current) ?? $current);
    return str_ends_with($current, '_RESV') ? 'On reserve at ' . $name : $name;
  }

  /**
   * A list of the service's codes and their names, kept for a day. A code with
   * no name shows as itself, and a failure isn't kept.
   */
  private function names(string $list): array {
    $key = 'palefire_search:catalog_names:' . $list;
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }
    $answer = $this->service('admin/' . $list);
    if ($answer === NULL) {
      return [];
    }
    $names = [];
    foreach ($answer['policyInfo'] ?? [] as $policy) {
      if (isset($policy['policyID'], $policy['policyDescription'])) {
        $names[$policy['policyID']] = trim($policy['policyDescription']);
      }
    }
    $this->cache->set($key, $names, time() + self::NAMES_KEEP);
    return $names;
  }

  /**
   * The search from the service, for when the feed is down.
   *
   * The service has no Type or Year filter that works. Rows ignoring a filter
   * someone chose would look like an answer, so that counts as a failure, and
   * the page offers the catalog's own site. Its answers aren't kept: the next
   * search should try the feed again.
   */
  private function serviceSearch(string $terms, array $filters, int $page, int $limit): array {
    $failed = ['total' => NULL, 'failed' => TRUE, 'items' => []];
    if (isset($filters['from']) || isset($filters['to'])) {
      return $failed;
    }
    $limits = '';
    foreach ($filters['refine'] ?? [] as $option) {
      [$filter, , $value] = self::FILTERS[$option] ?? ['', '', ''];
      if ($filter === 'LIBRARY') {
        $limits .= '&libraryFilter=' . rawurlencode(substr($value, strpos($value, ':') + 1));
      }
      elseif ($filter === 'LANGUAGE') {
        $limits .= '&languageFilter=' . rawurlencode($value);
      }
      else {
        return $failed;
      }
    }

    $answer = $this->service('standard/searchCatalog', 'term1=' . rawurlencode($terms) . '&hitsToDisplay=' . $limit . $limits);
    if ($answer === NULL) {
      return $failed;
    }
    $total = (int) ($answer['totalHits'] ?? 0);
    $reachable = min($total, self::MAX_RECORDS);
    $page = min($page, (int) floor(max(0, $reachable - 1) / $limit));
    // Its search only gives the first page. The rest come from its query's ID.
    if ($page > 0) {
      $answer = $this->service('standard/searchCatalogPaging', 'queryID=' . rawurlencode((string) ($answer['queryID'] ?? '')) . '&firstHitToDisplay=' . ($page * $limit + 1) . '&lastHitToDisplay=' . ($page * $limit + $limit));
      if ($answer === NULL) {
        return $failed;
      }
    }

    $items = [];
    foreach ($answer['HitlistTitleInfo'] ?? [] as $hit) {
      $title = rtrim(ResultText::clean($hit['title'] ?? ''), ' /,:;=');
      if ($title === '' || empty($hit['titleID'])) {
        continue;
      }
      // Its author can end with web addresses for the same person.
      $author = trim(preg_replace('#\s*https?://\S+#', '', ResultText::clean($hit['author'] ?? '')) ?? '');
      $id = (string) $hit['titleID'];
      $items[] = [
        'title' => $title,
        'url' => ResultText::url(self::RECORD . 'ent:$002f$002fSD_LSU$002f0$002fSD_LSU:' . $id . '/one'),
        'summary' => '',
        'meta' => implode(' · ', array_filter([
          $this->withoutFinalPeriod($author),
          (string) ($hit['yearOfPublication'] ?? ''),
          ResultText::clean($hit['callNumber'] ?? ''),
        ])),
        'type' => self::KINDS[$hit['materialType'] ?? ''] ?? '',
        'full_text_url' => NULL,
        'holdings_id' => $id,
      ];
    }
    return [
      'total' => $total,
      'reachable' => $reachable,
      'capped' => FALSE,
      'failed' => FALSE,
      'backup' => TRUE,
      'items' => $items,
    ];
  }

  /**
   * An answer from the web service, or NULL if there isn't a usable one.
   */
  private function service(string $method, string $parameters = ''): ?array {
    $body = $this->fetch(self::SERVICE . $method . '?clientID=' . self::CLIENT . '&json=true' . ($parameters === '' ? '' : '&' . $parameters), self::SERVICE_TIMEOUT);
    $answer = $body === NULL ? NULL : json_decode($body, TRUE);
    if (!is_array($answer) || isset($answer['faultResponse']) || isset($answer['Fault'])) {
      if ($body !== NULL) {
        $this->loggerFactory->get('palefire_search')->error('Catalog service gave no usable answer to @method.', ['@method' => $method]);
      }
      return NULL;
    }
    return $answer;
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
  private function fetch(string $url, int $timeout = self::FEED_TIMEOUT): ?string {
    try {
      $response = $this->client->request('GET', $url, [
        'connect_timeout' => 5,
        'timeout' => $timeout,
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
      'holdings_id' => preg_match('#:(\d+)$#', $id, $match) ? $match[1] : NULL,
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

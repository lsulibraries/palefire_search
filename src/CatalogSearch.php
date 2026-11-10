<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;

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
   * A record's hold page, up to its encoded ID: the catalog's sign-in, then
   * the same form its Place Hold button opens.
   */
  private const HOLD = 'https://lsu.ent.sirsi.net/client/en_US/lsu/search/placehold/';

  /**
   * The most records the feed returns, however many it found.
   */
  private const MAX_RECORDS = 300;

  /**
   * How long a search is kept, in seconds.
   */
  private const KEEP = 3600;

  /**
   * Where an archive PDF's catalog record is kept, up to its path's hash.
   */
  private const AID_KEY = 'palefire_search:catalog_archive_pdf:';

  /**
   * The web service's address, and the public ID the catalog's own pages use.
   */
  private const SERVICE = 'https://lalu.sirsi.net/lalu_ilsws/rest/';
  private const CLIENT = 'DS_CLIENT';

  /**
   * The Libraries' shelf maps, which the catalog's own pages use, and the names
   * they know two libraries by.
   */
  private const MAPS = 'https://lsu.stackmap.com';
  private const MAP_LIBRARIES = [
    'LSU-MAIN' => 'LSU Library',
    'MUSIC' => 'Carter Music Resources Center',
  ];

  /**
   * The most holdings the maps answer about at once.
   */
  private const MAPS_AT_ONCE = 20;

  /**
   * The library whose copies are requested to the reading room, not held, and
   * its request form. Remote storage has a form of its own.
   */
  private const REQUEST_LIBRARY = 'SPEC_COLL';
  private const REQUEST_FORM = 'https://specialcollections.lib.lsu.edu/Logon/?Action=10&Form=20&';
  private const REMOTE_STORAGE = 'LAREMOTE';

  /**
   * The item type of archives and manuscripts, whose numbers say where on the
   * shelves a box stands.
   */
  private const ARCHIVE_TYPE = 'ARCH-MSS';

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
   * The most items listed for one shelf. The full record has the rest.
   */
  private const ITEMS_SHOWN = 20;

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
    $answer = $this->service('standard/lookupTitleInfo', implode('&', $titles) . '&includeItemInfo=true&includeAvailabilityInfo=true&includeMarcHoldings=true&includeCatalogingInfo=true&marcEntryFilter=ALL');
    if ($answer === NULL) {
      return NULL;
    }

    $libraries = $this->names('lookupLibraryPolicyList');
    $locations = $this->names('lookupLocationPolicyList');
    $types = $this->names('lookupItemTypePolicyList');
    $holdings = [];
    foreach ($answer['TitleInfo'] ?? [] as $title) {
      $holding = $this->holding($title, $libraries, $locations, $types);
      if ($holding) {
        $holdings[$holding['id']] = $holding;
      }
    }
    $holdings = $this->withMaps($holdings);
    $this->cache->set($key, $holdings, time() + self::HOLDINGS_KEEP);
    return $holdings;
  }

  /**
   * The catalog's copy number for each item of a record, by item ID.
   *
   * The service has none, so they come from the record's own page, which lists
   * each item's copy beside its ID. Asked for only when someone opens the
   * record's table. If the page fails or changes, the numbers are just missing.
   *
   * @return array
   *   Copy numbers keyed by item ID, empty if none could be read.
   */
  public function copyNumbers(string $id): array {
    $key = 'palefire_search:catalog_copies:' . $id;
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }
    $page = $this->fetch(self::RECORD . 'ent:$002f$002fSD_LSU$002f0$002fSD_LSU:' . $id . '/one', self::SERVICE_TIMEOUT);
    if ($page === NULL) {
      return [];
    }
    $copies = [];
    foreach (array_slice(explode('class="detailItemsTableRow', $page), 1) as $row) {
      if (preg_match('/detailItemsTable_COPY">\s*(\d+)\s*</', $row, $copy) && preg_match('/id="asyncFielddetailItemsDiv\d+SD_ITEM_STATUS([^"]+)"/', $row, $item)) {
        $copies[$item[1]] = (int) $copy[1];
      }
    }
    $this->cache->set($key, $copies, time() + self::NAMES_KEEP);
    return $copies;
  }

  /**
   * Adds the floor and a map link to copies the shelf maps know.
   *
   * Without the maps, copies just have neither.
   */
  private function withMaps(array $holdings): array {
    $wanted = [];
    foreach ($holdings as $id => $holding) {
      foreach ($holding['copies'] as $index => $copy) {
        if ($copy['map_library'] !== '' && $copy['first_call'] !== '') {
          $wanted[] = [$id, $index, $copy];
        }
      }
    }
    foreach (array_chunk($wanted, self::MAPS_AT_ONCE) as $chunk) {
      $asked = array_map(static fn(array $one): string => 'holding%5B%5D=' . rawurlencode($one[2]['map_library'] . '$$' . $one[2]['shelf'] . '$$' . $one[2]['first_call']), $chunk);
      $body = $this->fetch(self::MAPS . '/json/?callback=cb&alt=true&' . implode('&', $asked), self::SERVICE_TIMEOUT);
      $answer = $body === NULL ? NULL : json_decode(preg_replace('/^[^(]*\(|\)\s*;?\s*$/', '', $body) ?? '', TRUE);
      if (!is_array($answer)) {
        break;
      }
      foreach ($chunk as $position => [$id, $index, $copy]) {
        $map = $answer['results'][$position]['maps'][0] ?? NULL;
        if ($map) {
          $holdings[$id]['copies'][$index]['floor'] = (string) ($map['floorname'] ?? '');
          $holdings[$id]['copies'][$index]['map_url'] = ResultText::url(self::MAPS . '/view/?' . http_build_query([
            'v' => 'pf',
            'callno' => $copy['first_call'],
            'location' => $copy['shelf'],
            'library' => $copy['map_library'],
            'title' => $holdings[$id]['title'],
            'tt' => 0,
          ], '', '&', PHP_QUERY_RFC3986));
        }
      }
    }
    return $holdings;
  }

  /**
   * A copy's address on the Special Collections request form, filled in.
   */
  private function requestUrl(string $id, string $type, string $home, string $shelf, string $call, array $about): string {
    return self::REQUEST_FORM . http_build_query([
      'Value' => $home === self::REMOTE_STORAGE ? 'GenericRequestAllIronMountain' : 'GenericRequestAll',
      'ReferenceNumber' => $id,
      'DocumentType' => $type,
      'ItemTitle' => $about['title'],
      'ItemAuthor' => $about['author'],
      'ItemEdition' => $about['edition'],
      'CallNumber' => $call,
      'ItemPublisher' => $about['publisher'],
      'ItemDate' => $about['date'],
      'Location' => $shelf,
      'ItemPlace' => $about['place'],
      'ItemInfo1' => $about['access'],
    ], '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * What a record says about itself, from its cataloging fields.
   */
  private function about(array $title): array {
    $fields = [];
    foreach ($title['BibliographicInfo']['MarcEntryInfo'] ?? [] as $entry) {
      $fields[(string) ($entry['entryID'] ?? '')] ??= trim((string) ($entry['text'] ?? ''));
    }
    $published = $fields['264'] ?? $fields['260'] ?? '';
    [$place, $rest] = array_pad(explode(':', $published, 2), 2, '');
    $comma = strrpos($rest, ',');
    return [
      'title' => rtrim(preg_replace('#\s/\s.*$#u', '', $fields['245'] ?? '') ?? '', ' /,:;='),
      'author' => rtrim($fields['100'] ?? $fields['110'] ?? '', ' ,'),
      'edition' => $fields['250'] ?? '',
      'publisher' => trim($comma === FALSE ? $rest : substr($rest, 0, $comma)),
      'date' => preg_match('/\d{4}/', $published, $year) ? $year[0] : '',
      'place' => trim($place),
      'access' => $fields['506'] ?? '',
    ];
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
   *   title; url, the record's page; and copies, each [library, shelf, type,
   *   stack_location (its numbers are places on the shelves, not call numbers),
   *   call_number (empty where items have their own and nothing covers them all),
   *   general_call, call_count, call_more, first_call, map_library, floor,
   *   map_url, has (lines, less any an item took as its contents),
   *   available, total, online, reason, due, hold_url, request_url, reading_room,
   *   items, items_more]. reading_room: it's requested to view, not taken away. With more than one physical item on a shelf, items has each
   *   one's [item_id, call_number, contents, available, reason, due, request_url], and
   *   those carry the requests. NULL with no copies.
   */
  private function holding(array $title, array $libraries, array $locations, array $types): ?array {
    $id = (string) ($title['titleID'] ?? '');
    $groups = [];
    $blank = ['calls' => [], 'marc_call' => '', 'marc_calls' => [], 'holdings' => [], 'type' => '', 'available' => 0, 'total' => 0, 'reason' => '', 'due' => '', 'items' => []];

    foreach ($title['CallInfo'] ?? [] as $call) {
      $library = (string) ($call['libraryID'] ?? '');
      $number = trim((string) ($call['callNumber'] ?? ''));
      foreach ($call['ItemInfo'] ?? [] as $item) {
        $home = (string) ($item['homeLocationID'] ?? '');
        $current = (string) ($item['currentLocationID'] ?? $home);
        $group = $library . '|' . $home;
        $groups[$group] ??= $blank;
        $groups[$group]['total']++;
        $groups[$group]['type'] = $groups[$group]['type'] ?: (string) ($item['itemTypeID'] ?? '');
        if ($number !== '' && $number !== self::ONLINE_CALL_NUMBER) {
          $groups[$group]['calls'][$number] = TRUE;
        }
        $here = $current === $home && empty($item['dueDate']) && !in_array($current, self::AWAY, TRUE);
        $reason = $here ? '' : $this->reason($current, $locations);
        $due = $here || empty($item['dueDate']) ? '' : date('M j, Y', (int) ((int) $item['dueDate'] / 1000));
        if ($here) {
          $groups[$group]['available']++;
        }
        elseif ($groups[$group]['reason'] === '') {
          $groups[$group]['reason'] = $reason;
          $groups[$group]['due'] = $due;
        }
        $groups[$group]['items'][] = [
          'item_id' => (string) ($item['itemID'] ?? ''),
          'call_number' => $number,
          'contents' => [],
          'available' => $here,
          'reason' => $reason,
          'due' => $due,
        ];
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
        if ($number !== '') {
          $groups[$group]['marc_calls'][] = $number;
        }
        $groups[$group]['holdings'][] = ['call' => $number, 'lines' => $has];
      }
    }
    if ($id === '' || !$groups) {
      return NULL;
    }

    $about = $this->about($title);
    $url = ResultText::url(self::RECORD . 'ent:$002f$002fSD_LSU$002f0$002fSD_LSU:' . $id . '/one');
    $holdable = !empty($title['TitleAvailabilityInfo']['holdable']);
    $copies = [];
    foreach ($groups as $group => $found) {
      [$library, $home] = explode('|', $group, 2);
      $calls = array_map('strval', array_keys($found['calls']));
      $first_call = $found['marc_call'] ?: ($calls[0] ?? '');
      $online = $home === self::ONLINE;
      $type = $types[$found['type']] ?? $found['type'];
      $shelf = $locations[$home] ?? $home;
      $physical = !$online && $found['total'] > 0;
      $requested = $library === self::REQUEST_LIBRARY;
      $listed = $physical && $found['total'] > 1;
      $items = [];
      foreach ($listed ? array_slice($found['items'], 0, self::ITEMS_SHOWN) : [] as $item) {
        // A request names the box or volume wanted, not the whole run.
        $item['request_url'] = $requested ? $this->requestUrl($id, $type, $home, $shelf, $item['call_number'] ?: $first_call, $about) : NULL;
        $items[] = $item;
      }
      [$items, $has] = $this->withContents($items, $found['holdings']);
      // A holding's call number covers the shelf only if it isn't one item's own.
      $whole = $found['marc_calls'] && !array_intersect($found['marc_calls'], $calls) ? $found['marc_calls'][0] : '';
      $several = $listed && count($calls) > 1;
      // "LSU Library (Main Collection)" and "Special Collections, Hill Memorial
      // Library" are headed by their first part, as the catalog names them.
      $full = $libraries[$library] ?? $library;
      $name = preg_match('/^(.+?)\s*(?:\((.+)\)|,\s*(.+))$/u', $full, $part) ? [$part[1], ($part[2] ?? '') !== '' ? $part[2] : ($part[3] ?? '')] : [$full, ''];
      $copies[] = [
        'library' => $full,
        'library_name' => $name[0],
        'library_more' => $name[1],
        'shelf' => $shelf,
        'type' => $type,
        // An archive's boxes are found by where they stand, not by subject.
        'stack_location' => $found['type'] === self::ARCHIVE_TYPE,
        'call_number' => $whole ?: ($several ? '' : implode(', ', array_slice($calls, 0, 3))),
        'general_call' => $whole,
        // A shelf with one item: its copy number goes on the shelf's own row.
        'item_id' => $found['total'] === 1 ? ($found['items'][0]['item_id'] ?? '') : '',
        'call_count' => count($calls),
        'call_more' => $whole || $several ? 0 : max(0, count($calls) - 3),
        'first_call' => $first_call,
        'map_library' => $physical && !$requested ? (self::MAP_LIBRARIES[$library] ?? ($libraries[$library] ?? '')) : '',
        'floor' => '',
        'map_url' => NULL,
        // A hold needs the catalog's own sign-in, so this goes to its hold page.
        'hold_url' => $physical && !$requested && $holdable ? ResultText::url(self::HOLD . 'ent:$002f$002fSD_LSU$002f0$002fSD_LSU:' . $id) : NULL,
        'request_url' => $physical && $requested && !$listed ? $this->requestUrl($id, $type, $home, $shelf, $first_call, $about) : NULL,
        'reading_room' => $physical && $requested,
        'items' => $items,
        'items_more' => $listed ? max(0, $found['total'] - self::ITEMS_SHOWN) : 0,
        'has' => $has,
        'available' => $found['available'],
        'total' => $found['total'],
        'online' => $online,
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
      'title' => $about['title'],
      'url' => $url,
      'copies' => $copies,
    ];
  }

  /**
   * Gives each item the lines of holdings that belong to it.
   *
   * A holding with an item's call number is that item's: the catalog says so.
   * Where one holding covers a whole run, a line such as
   * "no.10(1962:Jan.),no.20(1963:Apr.) Box 1" belongs to the item whose call
   * number ends in "Box 1". That only goes by the words, and a line matching no
   * item is left as it was.
   *
   * @return array
   *   The items, and the lines left over.
   */
  private function withContents(array $items, array $holdings): array {
    $left = [];
    $lines = [];
    foreach ($holdings as $holding) {
      foreach ($items as $index => $item) {
        if ($holding['call'] !== '' && $item['contents'] === [] && $item['call_number'] === $holding['call']) {
          $items[$index]['contents'] = $holding['lines'];
          continue 2;
        }
      }
      array_push($lines, ...$holding['lines']);
    }
    foreach ($lines as $line) {
      $found = FALSE;
      if (preg_match('/^(.+?)[\s,;:.-]*\b(box\s*[\w.-]+|vol(?:ume|\.)?\s*[\w.-]+)$/iu', $line, $part)) {
        $name = ' ' . mb_strtolower((string) preg_replace('/\s+/u', ' ', $part[2]));
        foreach ($items as $index => $item) {
          if ($item['contents'] === [] && str_ends_with(' ' . mb_strtolower((string) preg_replace('/\s+/u', ' ', $item['call_number'])), $name)) {
            $items[$index]['contents'] = [$part[1]];
            $found = TRUE;
            break;
          }
        }
      }
      if (!$found) {
        $left[] = $line;
      }
    }
    return [$items, $left];
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
      if (!isset(self::FILTERS[$option])) {
        continue;
      }
      [$filter, , $value] = self::FILTERS[$option];
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
        'pdf_url' => NULL,
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
    $records = $body === NULL ? NULL : $this->parseFeed($body);
    if ($records !== NULL) {
      $this->cache->set($key, $records, time() + self::KEEP);
    }
    return $records;
  }

  /**
   * A feed's rows, or NULL if it couldn't be read.
   */
  private function parseFeed(string $body): ?array {
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
      'pdf_url' => $this->archivePdf($fields['Electronic Access'] ?? ''),
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
   * The catalog record for each of these archive PDFs, by its path.
   *
   * A record links its finding aid ("Finding aid:") or oral history abstract
   * ("Abstract:") under Electronic Access. The feed finds a finding aid by
   * "findaid" and its file's name, and an abstract by "oralhistory" and its
   * first tape number (T3498), or else its file's name, which the feed can't
   * match when it holds a space. Only a record that links that very file
   * counts. The searches run at once, each answer is kept for a day, and a PDF
   * with no record for an hour, in case one is added.
   *
   * @param string[] $paths
   *   Site paths from ResultText::archivePdf().
   *
   * @return array
   *   Each path's record ID, '' with no record, or NULL if the feed couldn't be
   *   read for it.
   */
  public function archivePdfRecords(array $paths): array {
    $found = [];
    $asked = [];
    foreach (array_unique($paths) as $path) {
      $kept = $this->cache->get($this->pdfKey($path));
      if ($kept) {
        $found[$path] = $kept->data;
        continue;
      }
      $found[$path] = NULL;
      $name = rawurldecode(basename($path));
      $terms = match (TRUE) {
        str_contains($path, '/findaid/') => 'findaid ' . basename($name, '.pdf'),
        (bool) preg_match('/(?<![A-Za-z0-9])T\d+/', $name, $tape) => 'oralhistory ' . $tape[0],
        default => $name,
      };
      // As in search(): these would break the feed's address.
      $terms = trim(preg_replace('#[/\\\\?\#%$]+#u', ' ', $terms) ?? '');
      $asked[$path] = $this->client->requestAsync('GET', self::FEED . rawurlencode($terms), [
        'connect_timeout' => 5,
        'timeout' => self::SERVICE_TIMEOUT,
        'http_errors' => FALSE,
        'headers' => ['User-Agent' => 'LSU Libraries search'],
      ]);
    }
    foreach (Utils::settle($asked)->wait() as $path => $answer) {
      $response = $answer['value'] ?? NULL;
      $records = $response && $response->getStatusCode() === 200 ? $this->parseFeed((string) $response->getBody()) : NULL;
      if ($records === NULL) {
        continue;
      }
      $id = '';
      foreach ($records as $record) {
        if ($record['holdings_id'] && $record['pdf_url'] !== NULL && $this->samePath($record['pdf_url'], $path)) {
          $id = $record['holdings_id'];
          break;
        }
      }
      $found[$path] = $id;
      $this->cache->set($this->pdfKey($path), $id, time() + ($id === '' ? self::KEEP : self::NAMES_KEEP));
    }
    return $found;
  }

  /**
   * Whether two site paths name the same file, however each is encoded.
   */
  private function samePath(string $a, string $b): bool {
    return mb_strtolower(rawurldecode($a)) === mb_strtolower(rawurldecode($b));
  }

  /**
   * Where an archive PDF's record is kept.
   */
  private function pdfKey(string $path): string {
    return self::AID_KEY . hash('sha256', mb_strtolower(rawurldecode($path)));
  }

  /**
   * The finding aid or oral history abstract a record links, as a site path,
   * or NULL.
   *
   * Records write it as "Finding aid:" or "Abstract:" and a link, often to
   * http://www.
   */
  private function archivePdf(string $access): ?string {
    $access = html_entity_decode($access, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('#href="([^"]+)"#i', $access, $links);
    foreach ($links[1] as $link) {
      $path = ResultText::archivePdf($link);
      if ($path !== NULL) {
        return $path;
      }
    }
    return NULL;
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

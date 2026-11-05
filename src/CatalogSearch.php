<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Searches the Catalog through its public results feed.
 *
 * The feed needs no login, and always answers with up to 300 records and no
 * total, so each search is read once, kept for a while, and paged here.
 * Everything vendor-specific stays here: when the Catalog moves to Alma, replace
 * this class and the rest of the module keeps its row shape.
 */
class CatalogSearch {

  /**
   * The feed's address, up to the search.
   */
  private const FEED = 'https://lsu.ent.sirsi.net/client/rss/hitlist/lsu/qu=';

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
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   *
   * @return array
   *   total, reachable, capped (TRUE when there may be more than the total),
   *   failed, and items.
   */
  public function search(string $terms, int $page, int $limit): array {
    $records = $this->records($terms);
    if ($records === NULL) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }
    $total = count($records);
    $page = min($page, (int) floor(max(0, $total - 1) / $limit));
    return [
      'total' => $total,
      'reachable' => $total,
      'capped' => $total >= self::MAX_RECORDS,
      'failed' => FALSE,
      'items' => array_slice($records, $page * $limit, $limit),
    ];
  }

  /**
   * Every record the feed gives for a search, or NULL if it couldn't be read.
   */
  private function records(string $terms): ?array {
    // These would break the feed's address, which holds the search in its path.
    $terms = trim(preg_replace('#[/\\\\?\#%$]+#u', ' ', $terms) ?? '');
    if ($terms === '') {
      return [];
    }
    $key = 'palefire_search:catalog:' . hash('sha256', $terms);
    $kept = $this->cache->get($key);
    if ($kept) {
      return $kept->data;
    }

    try {
      $response = $this->client->request('GET', self::FEED . rawurlencode($terms), [
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

    $status = $response->getStatusCode();
    $previous = libxml_use_internal_errors(TRUE);
    $feed = $status === 200 ? simplexml_load_string((string) $response->getBody(), \SimpleXMLElement::class, LIBXML_NONET) : FALSE;
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($feed === FALSE) {
      $this->loggerFactory->get('palefire_search')->error('Catalog search failed: status @status, or a feed that could not be read.', ['@status' => $status]);
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
        $author,
        ResultText::clean($fields['Publication Dates'] ?? $fields['Title Inclusive Dates'] ?? ''),
        ResultText::clean($fields['Call Number'] ?? ''),
      ])),
      'type' => ResultText::clean($fields['Format'] ?? ''),
      'full_text_url' => $this->fullText($fields['Electronic Access'] ?? ''),
    ];
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

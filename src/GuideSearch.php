<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Searches the research guides that carry one tag, such as Special Collections.
 *
 * The guides site's own search page lists a tag's guides from this address,
 * which needs no key: it answers with the results as HTML inside JSON, 20 pages
 * at a time, and a "Showing 20 of 94 Pages" line for the total. It's written
 * for that page and could change: if it can't be read, the section says so and
 * offers the guides site. Results are guide pages, so one guide can give more
 * than one row.
 */
class GuideSearch {

  /**
   * The search's address, and the guides site's page for the same search.
   */
  private const SEARCH = 'https://guides.lib.lsu.edu/srch_process_cs.php';

  /**
   * Results the address gives at a time.
   */
  private const PAGE_SIZE = 20;

  /**
   * How long an answer is kept, in seconds, and how long to wait for one.
   */
  private const KEEP = 600;
  private const TIMEOUT = 8;

  public function __construct(
    private readonly ClientInterface $client,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * One page of results.
   *
   * @param string $terms
   *   The search, words and phrases only.
   * @param int $tag
   *   The guides site's ID for the tag.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page, at most 20.
   *
   * @return array
   *   total, failed, and items: [title, url, summary, meta].
   */
  public function search(string $terms, int $tag, int $page, int $limit): array {
    $failed = ['total' => NULL, 'failed' => TRUE, 'items' => []];
    $limit = min($limit, self::PAGE_SIZE);
    // The address pages by 20, so a page of fewer is cut from its 20.
    $offset = $page * $limit;
    $start = intdiv($offset, self::PAGE_SIZE) * self::PAGE_SIZE;

    $key = 'palefire_search:guides:' . hash('sha256', $tag . '|' . $start . '|' . $terms);
    $kept = $this->cache->get($key);
    $answer = $kept ? $kept->data : $this->fetch($terms, $tag, $start);
    if ($answer === NULL) {
      return $failed;
    }
    if (!$kept) {
      $this->cache->set($key, $answer, time() + self::KEEP);
    }
    return [
      'total' => $answer['total'],
      'failed' => FALSE,
      'items' => array_slice($answer['items'], $offset - $start, $limit),
    ];
  }

  /**
   * One answer from the address, or NULL if it couldn't be read.
   */
  private function fetch(string $terms, int $tag, int $start): ?array {
    try {
      $response = $this->client->request('GET', self::SEARCH, [
        'query' => [
          'q' => $terms,
          'action' => 580,
          'search_source_id' => 0,
          'layout' => 'tab',
          'start' => $start,
          'group_id' => 0,
          'guide_id' => 0,
          'f_guide_tag_ids' => $tag,
          'sort' => '_score',
        ],
        'connect_timeout' => 5,
        'timeout' => self::TIMEOUT,
        'http_errors' => FALSE,
        'headers' => ['User-Agent' => 'LSU Libraries search', 'Accept' => 'application/json'],
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('palefire_search')->error('Guides search failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    $answer = $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), TRUE) : NULL;
    $html = $answer['data']['results'] ?? NULL;
    if ((int) ($answer['errCode'] ?? 0) !== 200 || !is_string($html)) {
      $this->loggerFactory->get('palefire_search')->error('Guides search gave no usable answer.');
      return NULL;
    }
    return [
      'total' => preg_match('/Showing\s+\d+\s+of\s+([\d,]+)/', $html, $match) ? (int) str_replace(',', '', $match[1]) : 0,
      'items' => $this->rows($html),
    ];
  }

  /**
   * The result rows in the answer's HTML.
   */
  private function rows(string $html): array {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new \DOMXPath($document);
    $class = static fn(string $name): string => "contains(concat(' ', normalize-space(@class), ' '), ' $name ')";

    $rows = [];
    foreach ($xpath->query('//div[' . $class('s-srch-result') . ']') as $result) {
      $link = $xpath->query('.//div[' . $class('s-srch-result-title') . ']/a', $result)->item(0);
      $url = $link instanceof \DOMElement ? $link->getAttribute('href') : '';
      $title = $link ? trim($link->textContent) : '';
      if ($title === '' || !preg_match('#^https://guides\.lib\.lsu\.edu/#', $url)) {
        continue;
      }
      $text = static fn(string $query): string => trim(preg_replace('/\s+/u', ' ', (string) $xpath->query($query, $result)->item(0)?->textContent) ?? '');
      $guide = $text('.//span[' . $class('s-srch-result-guide') . ']');
      $author = $text('.//span[' . $class('s-srch-result-author') . ']/a');
      $rows[] = [
        'title' => $title,
        'url' => ResultText::url($url),
        'summary' => $text('.//div[' . $class('s-srch-result-highlights') . ']'),
        // A guide's first page has the guide's own name: no need to say it twice.
        'meta' => implode(' · ', array_filter([$guide !== $title ? $guide : '', $author])),
      ];
    }
    return $rows;
  }

}

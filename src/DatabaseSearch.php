<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Searches the A-Z Databases list on the guides site.
 *
 * The list's own page fills its results from this address, which needs no
 * key: it answers with every result at once, as HTML inside JSON, and a count,
 * so the answer is kept and paged here. It reads a quoted phrase as nothing
 * found, so phrases are sent as their words. It matches
 * a word against each database's name, description and subjects, so a search
 * for nutrition finds the databases filed under Nutrition & Food Sciences, as
 * the list itself does. It's written for that page and could change: if it
 * can't be read, Databases falls back to the site's own Database tiles
 * (ExternalSearch::databases()).
 */
class DatabaseSearch {

  /**
   * The search's address, and the guides site's ID.
   */
  private const SEARCH = 'https://guides.lib.lsu.edu/process/az/dbsearch';
  private const SITE = 5512;

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
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   *
   * @return array
   *   total, failed, and items: [title, url, summary, meta]. meta: the
   *   list's own labels for a database, such as Trial or Open Access.
   */
  public function search(string $terms, int $page, int $limit): array {
    $terms = trim(preg_replace('/\s+/u', ' ', str_replace('"', ' ', $terms)) ?? '');
    if ($terms === '') {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }
    $limit = max(1, $limit);
    $key = 'palefire_search:databases:' . hash('sha256', $terms);
    $kept = $this->cache->get($key);
    $answer = $kept ? $kept->data : $this->fetch($terms);
    if ($answer === NULL) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }
    if (!$kept) {
      $this->cache->set($key, $answer, time() + self::KEEP);
    }
    return [
      'total' => $answer['total'],
      'failed' => FALSE,
      'items' => array_slice($answer['items'], $page * $limit, $limit),
    ];
  }

  /**
   * One answer from the address, or NULL if it couldn't be read.
   */
  private function fetch(string $terms): ?array {
    try {
      $response = $this->client->request('GET', self::SEARCH, [
        'query' => [
          'search' => $terms,
          'subject_id' => '',
          'type_id' => '',
          'vendor_id' => '',
          'access_mode_id' => '',
          // Every result comes back whatever these say.
          'page' => 1,
          'site_id' => self::SITE,
          'content_id' => 0,
          'is_widget' => 0,
          'bootstrap5' => 0,
          'page_size' => 10,
          'preview' => '',
          'alpha' => '',
        ],
        'connect_timeout' => 5,
        'timeout' => self::TIMEOUT,
        'http_errors' => FALSE,
        'headers' => ['User-Agent' => 'LSU Libraries search', 'Accept' => 'application/json'],
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('palefire_search')->error('Databases search failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    $answer = $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), TRUE) : NULL;
    $html = $answer['data']['html'] ?? NULL;
    if ((int) ($answer['errCode'] ?? 0) !== 200 || !is_string($html) || !isset($answer['data']['count'])) {
      $this->loggerFactory->get('palefire_search')->error('Databases search gave no usable answer.');
      return NULL;
    }
    return [
      'total' => (int) $answer['data']['count'],
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
    foreach ($xpath->query('//div[' . $class('s-lg-az-result') . ']') as $result) {
      $link = $xpath->query('.//div[' . $class('s-lg-az-result-title') . ']/a', $result)->item(0);
      $url = $link instanceof \DOMElement ? $link->getAttribute('href') : '';
      $title = $link ? trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '') : '';
      if ($title === '' || !preg_match('#^https?://#', $url)) {
        continue;
      }
      $text = static fn(string $query): string => trim(preg_replace('/\s+/u', ' ', (string) $xpath->query($query, $result)->item(0)?->textContent) ?? '');
      $rows[] = [
        'title' => $title,
        'url' => ResultText::url($url),
        'summary' => $text('.//div[' . $class('s-lg-az-result-description') . ']'),
        'meta' => $text('.//div[' . $class('s-lg-az-result-badges') . ']'),
      ];
    }
    return $rows;
  }

}

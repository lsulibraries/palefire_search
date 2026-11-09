<?php

declare(strict_types=1);

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;

/**
 * How long a PDF on this site is: its pages and its size.
 *
 * Shown beside a result's Open PDF button, so it's plain what opening it means.
 * Only files under the site's own files folders are read, never another host.
 * Each file is read once and remembered until it changes.
 */
final class PdfFacts {

  /**
   * Larger files aren't read for their pages: their size still shows.
   */
  private const READ_MAX = 30 * 1024 * 1024;

  /**
   * More pages than this is a misread, so none are shown.
   */
  private const PAGES_MAX = 20000;

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly string $root,
  ) {}

  /**
   * [pages, size] for a link to a PDF on this site, or [] for any other link.
   *
   * pages is an int, or NULL when the file can't be read for them. size is
   * "296 KB" and the like.
   */
  public function forUrl(string $url): array {
    $path = $this->localPath($url);
    if ($path === NULL || !is_file($path)) {
      return [];
    }
    $bytes = (int) @filesize($path);
    $modified = (int) @filemtime($path);
    if ($bytes <= 0) {
      return [];
    }
    $cid = 'palefire_search:pdf_facts:' . hash('sha256', $path) . ':' . $modified . ':' . $bytes;
    if ($cached = $this->cache->get($cid)) {
      $pages = $cached->data;
    }
    else {
      $pages = $bytes <= self::READ_MAX ? $this->pages($path) : NULL;
      $this->cache->set($cid, $pages, CacheBackendInterface::CACHE_PERMANENT, ['palefire_search_pdf_facts']);
    }
    return [
      'pages' => $pages,
      'size' => ByteSizeMarkup::create($bytes),
    ];
  }

  /**
   * The file on disk a link points at, or NULL if it isn't one of ours.
   *
   * Finding aids are in sites/default/files, oral history abstracts in
   * sites/all/files. Some links still name www. or http.
   */
  private function localPath(string $url): ?string {
    if (!preg_match('#^(?:https?://(?:www\.)?lib\.lsu\.edu)?(/sites/(?:default|all)/files/[^?\#]+\.pdf)$#i', $url, $m)) {
      return NULL;
    }
    $relative = rawurldecode($m[1]);
    if (str_contains($relative, '..') || str_contains($relative, "\0")) {
      return NULL;
    }
    return $this->root . $relative;
  }

  /**
   * The page count from the file's page tree, or NULL.
   *
   * The root of the tree counts every page: the largest /Count on a /Pages
   * node. Newer files keep it in compressed object streams, so those are
   * inflated and searched too.
   */
  private function pages(string $path): ?int {
    $raw = @file_get_contents($path);
    if (!is_string($raw) || !str_starts_with($raw, '%PDF')) {
      return NULL;
    }
    $count = $this->largestCount($raw);
    if ($count === 0 && function_exists('gzuncompress')) {
      preg_match_all('/stream\r?\n/', $raw, $starts, PREG_OFFSET_CAPTURE);
      foreach ($starts[0] as [$marker, $offset]) {
        $start = $offset + strlen($marker);
        $end = strpos($raw, 'endstream', $start);
        if ($end === FALSE) {
          break;
        }
        $data = @gzuncompress(rtrim(substr($raw, $start, $end - $start), "\r\n"));
        if (is_string($data) && str_contains($data, '/Pages')) {
          $count = max($count, $this->largestCount($data));
        }
      }
    }
    return $count > 0 && $count <= self::PAGES_MAX ? $count : NULL;
  }

  /**
   * The largest /Count beside a /Type /Pages in some PDF source, or 0.
   */
  private function largestCount(string $source): int {
    preg_match_all('#/Type\s*/Pages\b[^>]{0,4000}?/Count\s+(\d+)|/Count\s+(\d+)[^>]{0,4000}?/Type\s*/Pages\b#', $source, $m);
    $counts = array_map('intval', array_merge(array_filter($m[1]), array_filter($m[2])));
    return $counts ? max($counts) : 0;
  }

}

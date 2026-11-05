<?php

namespace Drupal\palefire_search;

use Drupal\views\ResultRow;
use Drupal\views\Views;

/**
 * Searches outside services through their existing Views.
 *
 * Runs the View for its data and ignores its markup. The connector modules
 * (eds, libguides) keep the credentials and the calls. When a service moves
 * (Discovery to Alma), add an adapter and point the source at the new View.
 */
class ExternalSearch {

  /**
   * One page of results.
   *
   * The connectors catch their errors and return nothing, so a failure looks
   * like no results. They leave the View's total unset when that happens,
   * while a real empty search has a total of 0: that's what "failed" reads.
   *
   * @return array
   *   total, failed, and items: [title, url, external, summary, meta, source,
   *   image_url].
   */
  public function search(array $source, string $query, int $page, int $limit, string $sort = 'relevance'): array {
    $view = Views::getView($source['view']);
    if (!$view || !$view->setDisplay($source['display'])) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }

    if (isset($source['sort_handler'], $source['sort_values'][$sort])) {
      $view->setHandlerOption($source['display'], 'sort', $source['sort_handler'], 'order', $source['sort_values'][$sort]);
    }

    // Each service reads operators its own way.
    $terms = match ($source['adapter']) {
      'discovery' => SearchTerms::forDiscovery($query),
      'guides' => SearchTerms::forGuides($query),
      default => $query,
    };
    if ($terms === '') {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }
    // Research Guides can't honor operators, and the page says so.
    $simplified = $source['adapter'] === 'guides' && SearchTerms::hasOperators($query) ? $terms : NULL;

    // The connectors put the term into an address as is, so "r&b" would be
    // cut at the "&". Encoding it here carries it through whole, without
    // changing the connector modules.
    $view->setExposedInput(['search_api_fulltext_bento' => rawurlencode($terms)]);
    $view->setItemsPerPage($limit);
    $view->setCurrentPage($page);
    $view->execute();

    if ($view->total_rows === NULL) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }

    $words = SearchSources::indexableWords($query);
    $items = [];
    foreach ($view->result as $row) {
      $item = match ($source['adapter']) {
        'discovery' => $this->discovery($row),
        'guides' => $this->guides($row),
        default => NULL,
      };
      if ($item) {
        $item['title'] = ResultText::highlight(ResultText::clean($item['title']), $words);
        $item['summary'] = ResultText::highlight(ResultText::summary($item['summary']), $words);
        $item['meta'] = ResultText::clean($item['meta']);
        $item['external'] = TRUE;
        $item['source'] = NULL;
        $items[] = $item;
      }
    }
    return ['total' => (int) $view->total_rows, 'failed' => FALSE, 'items' => $items, 'simplified' => $simplified];
  }

  /**
   * A Discovery record. Its details are a list of named items.
   */
  private function discovery(ResultRow $row): ?array {
    $details = [];
    foreach ($row->items ?? [] as $detail) {
      $details[$detail['Name'] ?? ''][] = $detail['Data'] ?? '';
    }
    $title = $details['Title'][0] ?? '';
    if ($title === '') {
      return NULL;
    }
    $url = (string) ($row->p_link ?? '');
    // Off campus, a record's link has to pass through the proxy.
    if ($url !== '' && !str_contains($url, 'libezp.lib.lsu.edu')) {
      $url = 'https://libezp.lib.lsu.edu/login?url=' . $url;
    }
    return [
      'title' => $title,
      'url' => ResultText::url($url),
      'summary' => $details['Abstract'][0] ?? '',
      'meta' => implode(' · ', array_filter([
        $row->header['PubType'] ?? '',
        ResultText::clean($details['Author'][0] ?? ''),
      ])),
      'image_url' => $this->cover($row),
    ];
  }

  /**
   * A record's cover from Discovery: the larger size if there's a choice.
   *
   * Over https only, since a page on https shouldn't load mixed content.
   */
  private function cover(ResultRow $row): ?string {
    $covers = [];
    foreach ($row->image_info ?? [] as $image) {
      $covers[$image['Size'] ?? ''] = (string) ($image['Target'] ?? '');
    }
    $url = $covers['medium'] ?? $covers['thumb'] ?? '';
    return str_starts_with($url, 'https://') ? $url : NULL;
  }

  /**
   * A research guide.
   */
  private function guides(ResultRow $row): ?array {
    $title = (string) ($row->name ?? '');
    return $title === '' ? NULL : [
      'title' => $title,
      'url' => ResultText::url((string) ($row->url ?? '')),
      'summary' => (string) ($row->description ?? ''),
      'meta' => (string) ($row->subjects ?? ''),
    ];
  }

}

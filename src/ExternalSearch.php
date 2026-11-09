<?php

namespace Drupal\palefire_search;

use Drupal\views\ResultRow;
use Drupal\views\Views;

/**
 * Searches outside services.
 *
 * Discovery has its own class, for the filters its View can't pass, and the
 * Catalog has one for its feed. Research
 * Guides runs its View for the data and ignores the markup. The connector
 * modules (eds, libguides) keep the credentials and the calls.
 */
class ExternalSearch {

  public function __construct(
    private readonly DiscoverySearch $discovery,
    private readonly CatalogSearch $catalog,
  ) {}

  /**
   * One page of results.
   *
   * The connectors catch their errors and return nothing, so a failure looks
   * like no results. They leave the View's total unset when that happens,
   * while a real empty search has a total of 0: that's what "failed" reads.
   *
   * @param array $source
   *   The source's definition.
   * @param string $query
   *   The search as typed.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   * @param string $sort
   *   The sort's name.
   * @param array $filters
   *   refine: the source's chosen filters; from, to: years; full: this is the
   *   source's own list, so its total and filter counts are worth reading.
   *
   * @return array
   *   total, reachable (when fewer can be paged to), capped (when there may be
   *   more than the total), failed, and items: [title,
   *   url, external, summary, meta, source, type, full_text_url, image_url,
   *   holdings_id]. backup is TRUE when a stand-in service answered.
   */
  public function search(array $source, string $query, int $page, int $limit, string $sort = 'relevance', array $filters = []): array {
    // Each service reads operators its own way.
    $terms = match ($source['adapter']) {
      'discovery' => SearchTerms::forDiscovery($query),
      // Words and phrases only: the Catalog's feed reads AND and NOT as words.
      'guides', 'catalog' => SearchTerms::forGuides($query),
      default => $query,
    };
    if ($terms === '') {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }

    $results = match ($source['adapter']) {
      'discovery' => $this->discovery->search($terms, $filters, $page, $limit, $source['sort_values'][$sort] ?? 'relevance'),
      'catalog' => $this->catalog->search($terms, $filters, $page, $limit),
      default => $this->guidesResults($source, $terms, $page, $limit),
    };

    // Some services can't honor operators, and the page says so.
    $results['simplified'] = in_array($source['adapter'], ['guides', 'catalog'], TRUE) && SearchTerms::hasOperators($query) ? $terms : NULL;

    $words = SearchSources::indexableWords($query);
    foreach ($results['items'] as $index => $item) {
      $item['title'] = ResultText::highlight(ResultText::clean($item['title']), $words);
      $item['summary'] = ResultText::highlight(ResultText::summary($item['summary']), $words);
      $item['meta'] = ResultText::clean($item['meta']);
      $item['external'] = TRUE;
      $item['source'] = NULL;
      $results['items'][$index] = $item;
    }
    return $results;
  }

  /**
   * Where the Catalog's records are held, by record ID, or NULL on a failure.
   */
  public function catalogHoldings(array $ids): ?array {
    return $this->catalog->holdings($ids);
  }

  /**
   * A Catalog record's copy numbers, by item ID.
   */
  public function catalogCopies(string $id): array {
    return $this->catalog->copyNumbers($id);
  }

  /**
   * Filter counts from a service's latest search, by filter name.
   */
  public function counts(array $source): array {
    return match ($source['adapter']) {
      'discovery' => $this->discovery->counts(),
      'catalog' => $this->catalog->counts(),
      default => [],
    };
  }

  /**
   * A page of Research Guides from its View.
   */
  private function guidesResults(array $source, string $terms, int $page, int $limit): array {
    $view = Views::getView($source['view']);
    if (!$view || !$view->setDisplay($source['display'])) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }

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

    $items = array_values(array_filter(array_map($this->guides(...), $view->result)));
    return ['total' => (int) $view->total_rows, 'failed' => FALSE, 'items' => $items];
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

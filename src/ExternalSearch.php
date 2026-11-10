<?php

namespace Drupal\palefire_search;

use Drupal\views\ResultRow;
use Drupal\views\Views;

/**
 * Searches outside services.
 *
 * Discovery has its own class, for the filters its View can't pass, the
 * Catalog has one for its feed, and Journals one for the titles and years
 * Discovery's service holds. A tag's guides, such as Special Collections
 * Guides, come from the guides site's own search. Research
 * Guides runs its View for the data and ignores the markup. The connector
 * modules (eds, libguides) keep the credentials and the calls.
 */
class ExternalSearch {

  public function __construct(
    private readonly DiscoverySearch $discovery,
    private readonly CatalogSearch $catalog,
    private readonly JournalSearch $journals,
    private readonly GuideSearch $guideSearch,
    private readonly PdfFacts $pdfFacts,
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
   *   url, external, summary, meta, source, type, full_text_url, pdf_url (a
   *   Catalog record's finding aid), pdf, image_url,
   *   holdings_id, and for a journal coverage and coverage_more]. backup is
   *   TRUE when a stand-in service answered. journal is the journal whose name
   *   is the search, when Discovery offers one, as a row.
   */
  public function search(array $source, string $query, int $page, int $limit, string $sort = 'relevance', array $filters = []): array {
    // Each service reads operators its own way.
    $terms = match ($source['adapter']) {
      // Journals is Discovery's service, so it reads the same operators.
      'discovery', 'journals' => SearchTerms::forDiscovery($query),
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
      'journals' => $this->journals->search($terms, $filters, $page, $limit),
      default => $this->guidePages($source, $terms, $page, $limit),
    };

    // Some services can't honor operators, and the page says so.
    $results['simplified'] = in_array($source['adapter'], ['guides', 'catalog'], TRUE) && SearchTerms::hasOperators($query) ? $terms : NULL;

    $words = SearchSources::indexableWords($query);
    $results['items'] = array_map(fn(array $item): array => $this->shown($item, $words), $results['items']);
    if (!empty($results['journal'])) {
      // Its title is the search, so marking the words would mark all of it.
      $results['journal'] = $this->shown($results['journal'], []);
    }
    return $results;
  }

  /**
   * A service's row as the page shows it: escaped, with the searched words
   * marked.
   */
  private function shown(array $item, array $words): array {
    $item['title'] = ResultText::highlight(ResultText::clean($item['title']), $words);
    $item['summary'] = ResultText::highlight(ResultText::summary($item['summary']), $words);
    // A guide page's words around the match, marked as an archive record's are.
    $item['excerpt'] = ($item['excerpt'] ?? '') === '' ? '' : ResultText::highlight(ResultText::clean($item['excerpt']), $words);
    $item['meta'] = ResultText::clean($item['meta']);
    $item['external'] = TRUE;
    $item['source'] = NULL;
    // A Catalog record's finding aid or oral history abstract is a PDF on this
    // site, as long as one listed under PDF Finding Aids.
    $item['pdf'] = !empty($item['pdf_url']) ? $this->pdfFacts->forUrl($item['pdf_url']) : [];
    return $item;
  }

  /**
   * The Catalog record for each finding aid or oral history abstract, by its
   * PDF's path. See CatalogSearch::archivePdfRecords().
   */
  public function archivePdfRecords(array $paths): array {
    return $this->catalog->archivePdfRecords($paths);
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
    // Journals has none: the service drops its Type counts once a type is
    // chosen, and it always searches with one.
    return match ($source['adapter']) {
      'discovery' => $this->discovery->counts(),
      'catalog' => $this->catalog->counts(),
      default => [],
    };
  }

  /**
   * A page of research guides from the guides site's own search, which shows
   * the words around each match. Only Research Guides itself has its View to
   * fall back on, which has no such words, when that search can't be read.
   */
  private function guidePages(array $source, string $terms, int $page, int $limit): array {
    $results = $this->guideSearch->search($terms, $source['tag_id'] ?? NULL, $page, $limit);
    return $results['failed'] && isset($source['view']) ? $this->guidesResults($source, $terms, $page, $limit) : $results;
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

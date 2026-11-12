<?php

namespace Drupal\palefire_search\Controller;

use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\palefire_search\ExternalSearch;
use Drupal\palefire_search\LocalSearch;
use Drupal\palefire_search\ResultText;
use Drupal\palefire_search\SearchAnswers;
use Drupal\palefire_search\SearchSources;
use Drupal\palefire_search\SearchTerms;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Index's page and its late-loading sections.
 *
 * Everything is a plain GET: every search, mode, filter and sort is an address
 * that can be shared, and the page works without JavaScript.
 *
 * Filters: options within a filter widen the results, and different filters
 * narrow them. Choosing a record type limits the results to that record type's
 * source, so "Oral histories" never leaves unrelated news in the list.
 */
class SearchController extends ControllerBase {

  /**
   * Results per section in a sections layout, unless a source sets its own.
   */
  private const SECTION_LIMIT = 3;

  /**
   * When a search finds this many sources or fewer, each shows more.
   */
  private const ROOMY_SECTIONS = 2;

  /**
   * Results per section then: a site source's, and an outside one's.
   */
  private const ROOMY_LIMIT = 10;

  private const ROOMY_EXTERNAL_LIMIT = 8;

  /**
   * Extra Catalog rows asked for when its oral histories are left out.
   */
  private const HIDDEN_SPARE = 5;

  /**
   * Results per page in a list.
   */
  private const LIST_LIMIT = 20;

  /**
   * Longest search, as the Views allowed.
   */
  private const MAX_QUERY = 128;

  /**
   * How long a successful answer from a service may be kept, in seconds.
   */
  private const EXTERNAL_MAX_AGE = 600;

  /**
   * How long browsers may keep availability, in seconds.
   */
  private const HOLDINGS_MAX_AGE = 120;

  /**
   * Seconds a record's copy numbers may be kept: they rarely change.
   */
  private const COPIES_MAX_AGE = 3600;

  /**
   * The filter value that says no to a filter's default.
   */
  private const ANY = 'any';

  /**
   * The Webform that takes feedback on the page, built in the site's UI.
   */
  private const FEEDBACK_FORM = 'combined_search_feedback';

  public function __construct(
    private readonly LocalSearch $local,
    private readonly ExternalSearch $external,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RendererInterface $renderer,
    private readonly KillSwitch $killSwitch,
    private readonly AssetQueryStringInterface $assetQueryString,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('palefire_search.local'),
      $container->get('palefire_search.external'),
      $container->get('pager.manager'),
      $container->get('renderer'),
      $container->get('page_cache_kill_switch'),
      $container->get('asset.query_string'),
    );
  }

  /**
   * The page title.
   */
  public function title(): string {
    return (string) $this->t('Combined Search');
  }

  /**
   * The page, or a move to the mode the search now belongs to.
   */
  public function page(Request $request): array|LocalRedirectResponse {
    $parameters = $request->query->all();
    $query = $this->query($parameters);
    $modes = SearchSources::modes();
    $mode_key = is_string($parameters['mode'] ?? NULL) && isset($modes[$parameters['mode']]) ? $parameters['mode'] : 'all';
    $mode = $modes[$mode_key];

    // year:1860-1865 in the search is the Year published filter: the address
    // takes it from the search, so its chip and fields show it as if chosen.
    if ($query !== '' && ($typed = SearchTerms::years($query))) {
      $parameters['q'] = $typed['query'];
      unset($parameters['search_api_fulltext_bento'], $parameters['page'], $parameters['from'], $parameters['to']);
      $parameters += array_filter(['from' => $typed['from'], 'to' => $typed['to']], static fn(?int $year): bool => $year !== NULL);
      $response = new LocalRedirectResponse(Url::fromRoute('palefire_search.page', [], ['query' => array_filter($parameters, static fn($value): bool => $value !== '' && $value !== [])])->toString());
      $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
      return $response;
    }

    [$chosen, $refines] = $this->selection($parameters, $mode);
    if ($query !== '' && ($moved = $this->moved($mode, $chosen, $refines, $query, $parameters))) {
      return $moved;
    }
    $selected = array_values(array_intersect($mode['sources'], array_unique([...$chosen, ...array_keys($refines)])));
    // With nothing chosen, every source is searched, so the years go to those
    // with a Year published filter.
    $years = !$selected || array_filter($selected, static fn(string $key): bool => !empty(SearchSources::get($key)['year_filter'])) ? $this->years($parameters) : [];
    $sorts = SearchSources::sorts($selected);
    $sort = is_string($parameters['sort'] ?? NULL) && isset($sorts[$parameters['sort']]) ? $parameters['sort'] : 'relevance';
    $page = is_scalar($parameters['page'] ?? NULL) ? max(0, (int) $parameters['page']) : 0;

    $build = [
      '#theme' => 'palefire_search',
      '#query' => $query,
      '#mode' => $mode_key,
      '#action' => Url::fromRoute('palefire_search.page')->toString(),
      '#modes' => $this->modeLinks($modes, $mode_key, $query),
      '#attached' => [
        // Glossary: the source definitions use its cards.
        'library' => ['palefire/index-search', 'palefire/glossary'],
        // Results pages are endless variations of one page: keep them out of
        // search engines.
        'html_head' => [
          [
            ['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'noindex, follow']],
            'palefire_search_robots',
          ],
          // Without JavaScript there's no drawer to open, so the filters sit
          // in the page with their Apply button. In the head, so a visitor
          // with JavaScript never sees them jump away.
          [
            [
              '#tag' => 'style',
              '#value' => '.pf-index__filters{display:block;position:static;width:auto;height:auto;max-height:none;transform:none}.pf-index__apply{display:block}.pf-index__filters-open,.pf-index__filters-close{display:none}',
              '#noscript' => TRUE,
            ],
            'palefire_search_noscript',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.node_grants:view'],
        'tags' => ['search_api_list:' . SearchSources::INDEX],
      ],
    ];
    $build['#kept'] = $this->kept($chosen, $refines, $years, $sort);

    if ($query === '') {
      // The Feedback link beside Search tips is there before any search too.
      $build['#feedback'] = $this->feedback($build, $query, $mode_key);
      return $build;
    }

    $build['#links']= $this->outsideLinks($mode['links'], $query);
    $build['#answer'] = $page === 0 ? $this->answer($query) : NULL;
    if (!SearchSources::indexableWords($query)) {
      $build['#notice'] = SearchTerms::hasOperators($query)
        ? $this->t('The site index needs a word of two letters or more to look for, so its results are left out. Outside services still search for “@query”.', ['@query' => $query])
        : $this->t('The site index only matches words of two letters or more, so its results are left out. Outside services still search for “@query”.', ['@query' => $query]);
    }

    // The results first: they record counts the filters can then reuse.
    // A merged list can only merge local sources, so an outside one gets a section.
    $outside = array_filter($selected, static fn(string $key): bool => SearchSources::get($key)['kind'] === 'external');
    if (count($selected) === 1 || ($mode['layout'] === 'merged' && !$outside)) {
      // A source this mode leads with has its own section, so it's left out
      // of the list on every page.
      $leading = !$selected ? ($mode['lead'] ?? []) : [];
      [$build['#list'], $total, $build['#capped']] = $this->fullList($mode, $selected, $refines, $years, $query, $page, $sort, $leading);
      $build['#via'] = count($selected) === 1 ? $this->via(SearchSources::get($selected[0]), $query) : NULL;
      $build['#total'] = $total;
      if (count($sorts) > 1) {
        $build['#sort'] = $sort;
        $build['#sorts'] = array_map(fn(string $label): mixed => $this->t($label), $sorts);
      }
      // A source this mode leads with goes above its list, with that source's
      // own defaults. On the first page only: paging is through the list,
      // which keeps its name on every page, as it holds only what's left.
      if ($leading) {
        if ($page === 0) {
          $build['#sections'] = $this->sections($leading, $mode_key, $query, array_intersect_key($mode['refine_defaults'] ?? [], array_flip($leading)), $years);
        }
        $build['#list_title'] = $this->t($mode['list_label']);
        $build['#list_source'] = array_values(array_diff($mode['sources'], $leading))[0];
      }
    }
    else {
      $build['#sections'] = $this->sections($selected ?: $mode['sources'], $mode_key, $query, $refines, $years, TRUE);
    }

    $build['#filters'] = $this->filters($mode, $selected, $chosen, $refines, $years, $query);
    $build['#chips'] = $this->chips($mode_key, $query, $chosen, $refines, $years, $sort);
    $build['#clear_url'] = $this->address($mode_key, $query);
    $build['#selected_count'] = count($chosen) + array_sum(array_map(static fn(array $options): int => count(array_diff($options, [self::ANY])), $refines)) + ($years ? 1 : 0);
    $build['#feedback'] = $this->feedback($build, $query, $mode_key);
    return $build;
  }

  /**
   * The feedback question's address and what it says about this search.
   *
   * Each answer, and the Feedback link beside Search tips, opens the feedback
   * Webform with this search filled in: its
   * elements take these names from the address (Webform's prepopulate). Until
   * that Webform exists and is open, the page doesn't ask. Its cache tags bring
   * the question in the moment it opens and take it away when it closes.
   *
   * @return array|null
   *   [url, context], or NULL.
   */
  private function feedback(array &$build, string $query, string $mode_key): ?array {
    if (!$this->entityTypeManager()->hasDefinition('webform')) {
      return NULL;
    }
    $cache = CacheableMetadata::createFromRenderArray($build)
      ->addCacheTags(['config:webform_list', 'config:webform.webform.' . self::FEEDBACK_FORM]);
    $webform = $this->entityTypeManager()->getStorage('webform')->load(self::FEEDBACK_FORM);
    $url = NULL;
    if ($webform && $webform->isOpen()) {
      $generated = $webform->toUrl()->toString(TRUE);
      $cache->merge(CacheableMetadata::createFromObject($generated));
      $url = $generated->getGeneratedUrl();
    }
    $cache->applyTo($build);
    if (!$url) {
      return NULL;
    }
    $sources = array_column($build['#sections'] ?? [], 'key');
    if (!empty($build['#list_source'])) {
      $sources[] = $build['#list_source'];
    }
    $filters = array_map(static fn(array $chip): string => (string) $chip['label'], $build['#chips'] ?? []);
    return [
      'url' => $url,
      'context' => array_filter([
        'search_terms' => $query,
        'looking_for' => $query,
        'search_mode' => $mode_key,
        'result_count' => isset($build['#total']) ? (string) $build['#total'] : '',
        'sources_shown' => implode(', ', $sources),
        'filters_chosen' => implode('; ', $filters),
      ], static fn(string $value): bool => $value !== ''),
    ];
  }

  /**
   * One external source's section, as an HTML fragment.
   */
  public function source(Request $request, string $source): Response {
    $definition = SearchSources::get($source);
    if (($definition['kind'] ?? '') !== 'external') {
      throw new NotFoundHttpException();
    }
    $parameters = $request->query->all();
    $query = $this->query($parameters);
    $refines = $this->selection($parameters, ['sources' => [$source]])[1];
    $years = !empty($definition['year_filter']) ? $this->years($parameters) : [];
    // More rows when it's one of only a few sources (sections()).
    $limit = ($parameters['rows'] ?? NULL) === 'more' ? self::ROOMY_EXTERNAL_LIMIT : ($definition['section_limit'] ?? self::SECTION_LIMIT);
    // Oral history records, when their own section is beside this one
    // (sections()): a few more are asked for, so the section stays full.
    $hide = $source === 'catalog' && ($parameters['abstracts'] ?? NULL) === 'hide';
    $results = $query === ''
      ? ['total' => 0, 'failed' => FALSE, 'items' => []]
      : $this->external->search($definition, $query, 0, $hide ? $limit + self::HIDDEN_SPARE : $limit, 'relevance', ['refine' => $refines[$source] ?? [], ...$years]);
    if ($hide && $results['items']) {
      // The total stays the Catalog's own, which its Show all list holds. If
      // every row was one, they stay, so a count never heads an empty list.
      $kept = array_values(array_filter($results['items'], static fn(array $item): bool => !str_contains((string) ($item['pdf_url'] ?? ''), '/oralhistory/')));
      $results['items'] = array_slice($kept ?: $results['items'], 0, $limit);
    }

    $more_url = SearchSources::moreUrl($definition, $query);
    $more_label = $definition['more_label'] ?? NULL;
    // A source with its own list here sends people there. When it found nothing
    // or failed, its own site is still worth offering.
    if (!empty($definition['lists_here']) && !empty($results['items'])) {
      $modes = SearchSources::modes();
      $mode_key = is_string($parameters['mode'] ?? NULL) && isset($modes[$parameters['mode']]) ? $parameters['mode'] : 'all';
      $more_url = $results['total'] > $limit ? $this->address($mode_key, $query, [$source], $refines, 'relevance', $years) : NULL;
      $more_label = 'Show all @label results (@total)';
    }

    $list = $this->listBuild($definition['label'], $results, $more_url, $more_label);
    $response = new Response((string) $this->renderer->renderInIsolation($list));
    $this->applyExternalCaching($response, !empty($results['failed']) || !empty($results['backup']));
    return $response;
  }

  /**
   * Where the listed records are held, as an HTML fragment.
   *
   * On its own address because availability changes far more often than results
   * do, and a results page may be kept for a long time. This is a plain response,
   * which Drupal's page cache leaves alone, so it can stay this fresh.
   */
  public function holdings(Request $request): Response {
    $parameters = $request->query->all();
    $ids = $this->listed($parameters['ids'] ?? NULL, '/^\d{1,10}$/');
    // A finding aid's or oral history's copies are its Catalog record's, found
    // by its PDF's path.
    $pdfs = array_values(array_filter($this->listed($parameters['pdfs'] ?? NULL, '#^/sites/#'), static fn(string $path): bool => ResultText::archivePdf($path) === $path));
    $records_for = $pdfs ? $this->external->archivePdfRecords($pdfs) : [];
    $wanted = array_values(array_unique([...$ids, ...array_filter($records_for)]));
    $holdings = $this->external->catalogHoldings(array_slice($wanted, 0, self::LIST_LIMIT * 2));

    // The same record again for each PDF's row, under the PDF's path.
    $records = array_intersect_key($holdings ?? [], array_flip($ids));
    foreach ($records_for as $path => $id) {
      if ($id && isset($holdings[$id])) {
        $records['pdf:' . $path] = ['key' => 'pdf:' . $path] + $holdings[$id];
      }
    }

    $build = ['#theme' => 'palefire_search_holdings', '#records' => $records];
    $response = new Response((string) $this->renderer->renderInIsolation($build));
    if ($holdings === NULL || in_array(NULL, $records_for, TRUE)) {
      $this->applyExternalCaching($response, TRUE);
      return $response;
    }
    $response->setPublic();
    $response->setMaxAge(self::HOLDINGS_MAX_AGE);
    return $response;
  }

  /**
   * The chosen filters and sort as form fields, the same values address()
   * writes, so a new search from the search box keeps them. Not the page: a
   * new search starts at its first.
   *
   * @return array
   *   Each [name, value].
   */
  private function kept(array $chosen, array $refines, array $years, string $sort): array {
    $fields = [];
    foreach ($chosen as $key) {
      $fields[] = ['name' => 'source[]', 'value' => $key];
    }
    foreach ($refines as $key => $options) {
      foreach ($options as $option) {
        $fields[] = ['name' => 'refine[]', 'value' => $key . ':' . $option];
      }
    }
    foreach ($years as $name => $year) {
      $fields[] = ['name' => $name, 'value' => (string) $year];
    }
    if ($sort !== 'relevance') {
      $fields[] = ['name' => 'sort', 'value' => $sort];
    }
    return $fields;
  }

  /**
   * A comma-separated list from the address: the values that fit, once each,
   * at most a page's worth.
   */
  private function listed(mixed $value, string $pattern): array {
    $values = is_string($value) ? array_filter(explode(',', $value), static fn(string $one): bool => (bool) preg_match($pattern, $one)) : [];
    return array_slice(array_values(array_unique($values)), 0, self::LIST_LIMIT);
  }

  /**
   * One Catalog record's copy numbers, by item ID, as JSON. A failure answers
   * with none, kept only briefly.
   */
  public function copies(Request $request): JsonResponse {
    $id = $request->query->all()['id'] ?? NULL;
    $copies = is_string($id) && preg_match('/^\d{1,10}$/', $id) ? $this->external->catalogCopies($id) : [];
    $response = new JsonResponse((object) $copies);
    $response->setPublic();
    $response->setMaxAge($copies ? self::COPIES_MAX_AGE : 60);
    return $response;
  }

  /**
   * What's chosen in the address, keeping only what this mode offers.
   *
   * @return array
   *   The chosen sources, and the refinements keyed by source.
   */
  private function selection(array $parameters, array $mode): array {
    $sources = array_filter((array) ($parameters['source'] ?? []), 'is_string');
    $refine = array_filter((array) ($parameters['refine'] ?? []), 'is_string');
    // Oral histories were a record type of PDF Finding Aids until 2026-11-11:
    // addresses from then, saved or shared, find them as their own source.
    foreach ($refine as $index => $value) {
      if ($value === 'archive:oral_history') {
        $sources[] = 'oral_histories';
        unset($refine[$index]);
      }
      elseif (str_starts_with($value, 'archive:' . SearchSources::SERIES)) {
        $refine[$index] = 'oral_histories:' . substr($value, strlen('archive:'));
      }
    }
    $chosen = array_values(array_intersect($mode['sources'], array_unique($sources)));
    $refines = [];
    foreach ($refine as $value) {
      [$key, $option] = array_pad(explode(':', $value, 2), 2, '');
      $allowed = isset(SearchSources::get($key)['refine_options'][$option])
        || ($option === self::ANY && isset($mode['refine_defaults'][$key]))
        // A series is any name: it only narrows, so an unknown one finds nothing.
        || (isset(SearchSources::get($key)['series_field']) && str_starts_with($option, SearchSources::SERIES) && mb_strlen($option) <= 255);
      if (in_array($key, $mode['sources'], TRUE) && $allowed) {
        $refines[$key][] = $option;
      }
    }
    foreach ($refines as $key => $options) {
      $definition = SearchSources::get($key);
      if (!empty($definition['refine_single'])) {
        $refines[$key] = [...$this->oneToAGroup($options, $definition['refine_groups']), ...array_intersect($options, [self::ANY])];
      }
    }
    foreach ($mode['refine_defaults'] ?? [] as $key => $defaults) {
      $refines = $this->withDefaults($refines, $key, $defaults, in_array($key, $chosen, TRUE));
    }
    return [$chosen, array_map('array_unique', $refines)];
  }

  /**
   * The same search in another mode, once it no longer fits this one.
   *
   * Special Collections without its Catalog filter, archives or news is a
   * Research search: everything else chosen goes with it.
   */
  private function moved(array $mode, array $chosen, array $refines, string $query, array $parameters): ?LocalRedirectResponse {
    $unless = $mode['unless'] ?? NULL;
    if (!$unless || array_intersect($chosen, $unless['sources']) || array_intersect(array_keys($refines), $unless['sources'])) {
      return NULL;
    }
    foreach ($unless['refine'] as $key => $option) {
      // Not taken off: no filter of its own, or this one still among them.
      if (!isset($refines[$key]) || in_array($option, $refines[$key], TRUE)) {
        return NULL;
      }
    }
    $to = SearchSources::modes()[$unless['mode']];
    $sources = array_values(array_intersect($chosen, $to['sources']));
    $kept = [];
    foreach ($refines as $key => $options) {
      $options = array_values(array_diff($options, [self::ANY]));
      if ($options && in_array($key, $to['sources'], TRUE)) {
        $kept[$key] = $options;
      }
    }
    $sort = is_string($parameters['sort'] ?? NULL) ? $parameters['sort'] : 'relevance';
    $response = new LocalRedirectResponse($this->address($unless['mode'], $query, $sources, $kept, $sort, $this->years($parameters)));
    $response->getCacheableMetadata()->addCacheContexts(['url.query_args']);
    return $response;
  }

  /**
   * A source's filters, with its defaults where nothing else is chosen.
   *
   * The form sends a filter's default back along with everything else. That says
   * nothing without its source ticked, or a source could never be unticked.
   */
  private function withDefaults(array $refines, string $key, array $defaults, bool $chosen): array {
    $options = $refines[$key] ?? [];
    if (!$chosen) {
      if (!array_diff($options, [...$defaults, self::ANY])) {
        unset($refines[$key]);
      }
      return $refines;
    }
    if (in_array(self::ANY, $options, TRUE)) {
      return $refines;
    }
    foreach ($defaults as $default) {
      foreach (SearchSources::get($key)['refine_groups'] as $values) {
        if (in_array($default, $values, TRUE) && !array_intersect($values, $options)) {
          $refines[$key][] = $default;
        }
      }
    }
    return $refines;
  }

  /**
   * The last choice in each filter, for a service that takes only one.
   */
  private function oneToAGroup(array $options, array $groups): array {
    $kept = [];
    foreach ($groups as $values) {
      $chosen = array_values(array_intersect($options, $values));
      if ($chosen) {
        $kept[] = end($chosen);
      }
    }
    return $kept;
  }

  /**
   * The years chosen in the address, earliest first. Anything else is ignored.
   */
  private function years(array $parameters): array {
    $years = [];
    foreach (['from', 'to'] as $key) {
      $value = $parameters[$key] ?? NULL;
      if (is_string($value) && preg_match('/^[12]\d{3}$/', $value)) {
        $years[$key] = (int) $value;
      }
    }
    if (isset($years['from'], $years['to']) && $years['from'] > $years['to']) {
      [$years['from'], $years['to']] = [$years['to'], $years['from']];
    }
    return $years;
  }

  /**
   * The labelled sections. External ones load after the page.
   *
   * A section with no results is left out, so visitors see what's there. When
   * only a few sources are left, each shows more of what it found, unless a
   * list follows them ($roomy FALSE).
   */
  private function sections(array $keys, string $mode_key, string $query, array $refines, array $years, bool $roomy = FALSE): array {
    // The site's own sources first: their totals say how many sections there are.
    $found = [];
    $count = 0;
    foreach ($keys as $key) {
      $definition = SearchSources::get($key);
      if ($definition['kind'] === 'external') {
        $count++;
        continue;
      }
      $found[$key] = $this->local->search([$key], $query, $refines, 0, $roomy ? self::ROOMY_LIMIT : ($definition['section_limit'] ?? self::SECTION_LIMIT));
      if ($found[$key]['total'] > 0) {
        $count++;
      }
    }
    $roomy = $roomy && $count <= self::ROOMY_SECTIONS;

    $sections = [];
    foreach ($keys as $key) {
      $definition = SearchSources::get($key);
      if ($definition['kind'] === 'external') {
        $sections[] = [
          'key' => $key,
          'label' => $definition['label'],
          'about' => $definition['about'] ?? NULL,
          'via' => $this->via($definition, $query),
          'late_url' => Url::fromRoute('palefire_search.source', ['source' => $key], [
            'query' => array_filter([
              'q' => $query,
              'mode' => $mode_key === 'all' ? '' : $mode_key,
              'refine' => array_map(static fn(string $option): string => $key . ':' . $option, $refines[$key] ?? []),
              ...(!empty($definition['year_filter']) ? $years : []),
              'rows' => $roomy ? 'more' : '',
              // An oral history's Catalog record says nothing its own row
              // doesn't, so beside the Oral histories section it's left out.
              // Not when that section is narrowed to a series: the others'
              // interviews would be nowhere.
              'abstracts' => $key === 'catalog' && in_array('oral_histories', $keys, TRUE) && empty($refines['oral_histories']) ? 'hide' : '',
              // Kept for minutes by browsers and the page cache, so the address
              // carries the asset token, which every cache clear changes: after a
              // deploy and drush cr, no one gets a copy from the old code. The
              // holdings address does the same. Nothing reads v.
              'v' => $this->assetQueryString->get(),
            ]),
          ])->toString(),
          'full_url' => $this->address($mode_key, $query, [$key], array_intersect_key($refines, [$key => TRUE]), 'relevance', !empty($definition['year_filter']) ? $years : []),
        ];
        continue;
      }
      $results = $found[$key];
      if ($results['total'] > 0) {
        $limit = $roomy ? self::ROOMY_LIMIT : ($definition['section_limit'] ?? self::SECTION_LIMIT);
        $results['items'] = array_slice($results['items'], 0, $limit);
        $more = $results['total'] > $limit ? $this->address($mode_key, $query, [$key]) : NULL;
        $sections[] = [
          'key' => $key,
          'label' => $definition['label'],
          'about' => $definition['about'] ?? NULL,
          'count' => $this->countText($results['total'], !empty($results['capped'])),
          'list' => $this->listBuild($definition['label'], $results, $more, 'Show all @label results (@total)'),
        ];
      }
    }
    return $sections;
  }

  /**
   * The one list: a chosen source, or a merged mode's sources.
   *
   * @return array
   *   The list's render array, its total (NULL if it couldn't be reached), and
   *   whether there may be more than that total.
   */
  private function fullList(array $mode, array $selected, array $refines, array $years, string $query, int $page, string $sort, array $leading = []): array {
    $source = count($selected) === 1 ? SearchSources::get($selected[0]) : NULL;
    if ($source && $source['kind'] === 'external') {
      $results = $this->external->search($source, $query, $page, self::LIST_LIMIT, $sort, ['refine' => $refines[$selected[0]] ?? [], 'full' => TRUE, ...$years]);
      $brief = $results['failed'] || !empty($results['backup']);
      if ($brief) {
        // Never keep a failure: the next visitor should get a fresh try.
        $this->killSwitch->trigger();
      }
      $list = $this->listBuild($source['label'], $results, SearchSources::moreUrl($source, $query), $source['more_label'] ?? NULL, $page);
      $list['#cache'] = ['max-age' => $brief ? 0 : self::EXTERNAL_MAX_AGE];
      return [$list, $results['total'], !empty($results['capped'])];
    }
    $keys = $selected ?: array_values(array_filter(array_diff($mode['sources'], $leading), static fn(string $key): bool => SearchSources::get($key)['kind'] === 'local'));
    $results = $this->local->search($keys, $query, $refines, $page, self::LIST_LIMIT, $sort);
    $label = $source['label'] ?? (string) $this->t('Results');
    $more = $source ? SearchSources::moreUrl($source, $query) : NULL;
    return [$this->listBuild($label, $results, $more, $source['more_label'] ?? NULL, $page), $results['total'], FALSE];
  }

  /**
   * How many a source found, for its heading: "1 result", "300+ results".
   */
  private function countText(?int $total, bool $capped): string {
    if ($total === NULL) {
      return '';
    }
    return $capped
      ? (string) $this->t('@count+ results', ['@count' => number_format($total)])
      // formatPlural() sets @count to the bare number itself, so the formatted
      // one goes in its own placeholder.
      : (string) $this->formatPlural($total, '1 result', '@total results', ['@total' => number_format($total)]);
  }

  /**
   * A short answer above the results, for a search about a service.
   */
  private function answer(string $query): ?array {
    $answer = SearchAnswers::find($query);
    if (!$answer) {
      return NULL;
    }
    return [
      'key' => $answer['key'],
      'title' => $this->t($answer['title']),
      'locations' => array_map(fn(array $location): array => ['name' => $location['name'] === '' ? '' : $this->t($location['name'])] + $location, $answer['locations'] ?? []),
      'links' => array_map(fn(array $link): array => ['label' => $this->t($link[0]), 'url' => $link[1], 'external' => $link[2]], $answer['links'] ?? []),
    ];
  }

  /**
   * A results list's render array.
   */
  private function listBuild(string $label, array $results, ?string $more_url, ?string $more_label, ?int $page = NULL): array {
    foreach ($results['items'] as $index => $item) {
      $results['items'][$index]['login'] = ResultText::needsLogin((string) ($item['url'] ?? ''));
    }
    $build = [
      '#theme' => 'palefire_search_list',
      '#label' => $label,
      '#total' => $results['total'],
      '#count' => $this->countText($results['total'], !empty($results['capped'])),
      '#items' => $results['items'],
      '#journal' => $results['journal'] ?? NULL,
      '#failed' => $results['failed'] ?? FALSE,
      '#simplified' => $results['simplified'] ?? NULL,
      '#backup' => !empty($results['backup']),
      '#more_url' => $more_url,
      '#more_label' => $more_url && $more_label ? $this->t($more_label, ['@label' => $label, '@total' => number_format((int) $results['total']) . (!empty($results['capped']) ? '+' : '')]) : NULL,
    ];
    $held = array_filter(array_column($results['items'], 'holdings_id'));
    $pdfs = array_filter(array_column($results['items'], 'archive_pdf'));
    if ($held || $pdfs) {
      $build['#holdings_url'] = Url::fromRoute('palefire_search.holdings', [], ['query' => array_filter(['ids' => implode(',', $held), 'pdfs' => implode(',', $pdfs), 'v' => $this->assetQueryString->get()])])->toString();
    }
    // A service may find more than it will return. The total stays true and the
    // pager stops where the service does, which the last page explains.
    $reachable = $results['reachable'] ?? $results['total'];
    if ($page !== NULL && $reachable > self::LIST_LIMIT) {
      $this->pagerManager->createPager($reachable, self::LIST_LIMIT);
      $build['#pager'] = ['#type' => 'pager'];
    }
    if ($page !== NULL && ($results['total'] > $reachable || !empty($results['capped'])) && $page >= ceil($reachable / self::LIST_LIMIT) - 1) {
      $build['#limited'] = $reachable;
    }
    return $build;
  }

  /**
   * The mode tabs. Each is a link, because each is its own address.
   */
  private function modeLinks(array $modes, string $current, string $query): array {
    $links = [];
    foreach ($modes as $key => $mode) {
      $links[] = [
        'label' => $this->t($mode['label']),
        'url' => $this->address($key, $query),
        'current' => $key === $current,
      ];
    }
    return $links;
  }

  /**
   * The filter groups: Source, then each source's own filter.
   *
   * Each option counts on its own, so a count says what choosing it would
   * add. Options with nothing to add are disabled, unless they're chosen and
   * need a way back.
   * Outside services have no count, since one would mean calling them.
   */
  private function filters(array $mode, array $selected, array $chosen, array $refines, array $years, string $query): array {
    $options = [];
    $about = [];
    foreach ($mode['sources'] as $key) {
      $definition = SearchSources::get($key);
      if (isset($definition['about'])) {
        $about[] = ['label' => $definition['label'], 'text' => $definition['about']];
      }
      $count = $definition['kind'] === 'local' ? $this->local->count([$key], $query) : NULL;
      // Ticked only if chosen. A record type limits results to its source without
      // ticking it, or the form would send that back as a choice of its own.
      $checked = in_array($key, $chosen, TRUE);
      $options[] = ['label' => $definition['label'], 'value' => $key, 'count' => $count, 'checked' => $checked, 'disabled' => $count === 0 && !$checked];
    }
    // Not searched yet, so never counted or chosen: selection() only keeps sources.
    foreach ($mode['upcoming'] ?? [] as $key) {
      $definition = SearchSources::get($key);
      $about[] = ['label' => $definition['label'], 'text' => $definition['about']];
      $options[] = ['label' => $definition['label'], 'value' => $key, 'count' => NULL, 'checked' => FALSE, 'disabled' => TRUE];
    }
    $groups = [['title' => $this->t('Source'), 'name' => 'source[]', 'options' => $options, 'about' => $about]];

    // A source's own filter shows once that source is chosen, or always where
    // the mode leads with it. Each option is a query, so not all at once.
    foreach (array_unique([...($mode['refine_sources'] ?? []), ...$selected]) as $key) {
      $definition = SearchSources::get($key);
      // Its series, one at a time from a list under the source, once it or a
      // series is chosen. Every series is listed, those with no match disabled,
      // so the list is the same from one search to the next. A list keeps long
      // names whole and the filter one row tall, however many series there are.
      if (isset($definition['series_field']) && in_array($key, $selected, TRUE) && ($series_select = $this->seriesSelect($key, $refines[$key] ?? [], $query))) {
        foreach ($groups[0]['options'] as &$source) {
          if ($source['value'] === $key) {
            $source['sub'] = ['name' => 'refine[]', 'options' => [], 'series' => $series_select];
          }
        }
        unset($source);
      }
      if (!isset($definition['refine_options'])) {
        continue;
      }
      if (isset($definition['refine_groups'])) {
        array_push($groups, ...$this->serviceFilters($key, $definition, $refines, $years, $mode['refine_defaults'][$key] ?? []));
        continue;
      }
      $options = [];
      foreach ($definition['refine_options'] as $value => $label) {
        $value = (string) $value;
        $count = $this->local->count([$key], $query, [$key => [$value]]);
        $checked = in_array($value, $refines[$key] ?? [], TRUE);
        $options[] = ['label' => $label, 'value' => $key . ':' . $value, 'count' => $count, 'checked' => $checked, 'disabled' => $count === 0 && !$checked];
      }
      if ($options && !empty($definition['refine_nested'])) {
        // Under its source's own option, as a narrower choice of it.
        foreach ($groups[0]['options'] as &$source) {
          if ($source['value'] === $key) {
            $source['sub'] = ['name' => 'refine[]', 'options' => $options, 'series' => $source['sub']['series'] ?? NULL];
          }
        }
        unset($source);
      }
      elseif ($options) {
        $groups[] = ['title' => $this->t($definition['refine_label']), 'name' => 'refine[]', 'options' => $options];
      }
    }
    // What no choice means, said in the list: every source.
    $narrowed = FALSE;
    foreach ($groups[0]['options'] as $source) {
      $narrowed = $narrowed || $source['checked'] || in_array(TRUE, array_column($source['sub']['options'] ?? [], 'checked'), TRUE) || in_array(TRUE, array_column($source['sub']['series']['options'] ?? [], 'selected'), TRUE);
    }
    $groups[0]['all'] = ['label' => $this->t('All sources'), 'checked' => !$narrowed];
    return $groups;
  }

  /**
   * A source's series list: every series, with how many match, or NULL if the
   * index doesn't have its series yet. A chosen series stays, to be taken off,
   * though nothing matches now.
   */
  private function seriesSelect(string $key, array $refine, string $query): ?array {
    $counts = $this->local->seriesCounts($key, $query);
    if (!$counts) {
      return NULL;
    }
    $chosen = '';
    foreach ($refine as $option) {
      if (str_starts_with($option, SearchSources::SERIES)) {
        $chosen = substr($option, strlen(SearchSources::SERIES));
        $counts += [$chosen => 0];
        break;
      }
    }
    $options = [];
    foreach ($counts as $name => $count) {
      $name = (string) $name;
      $selected = $name === $chosen;
      $options[] = ['label' => $name, 'value' => $key . ':' . SearchSources::SERIES . $name, 'count' => $count, 'selected' => $selected, 'disabled' => $count === 0 && !$selected];
    }
    return ['name' => 'refine[]', 'options' => $options];
  }

  /**
   * An outside service's filter groups. Its search has just run, so its counts
   * cost nothing more. An option the service doesn't count has no number.
   */
  private function serviceFilters(string $key, array $definition, array $refines, array $years, array $defaults = []): array {
    $counts = $this->external->counts($definition);
    $single = !empty($definition['refine_single']);
    $groups = [];
    foreach ($definition['refine_groups'] as $title => $values) {
      $options = [];
      foreach ($values as $value) {
        $count = $counts[$value] ?? NULL;
        $checked = in_array($value, $refines[$key] ?? [], TRUE);
        $options[] = ['label' => $definition['refine_options'][$value], 'value' => $key . ':' . $value, 'count' => $count, 'checked' => $checked, 'disabled' => $count === 0 && !$checked];
      }
      if ($options && $single) {
        // Round buttons, which need a way back to no choice and a name of their
        // own in each filter. The empty value is ignored when it's read. A filter
        // with a default has to say Any out loud, or the default would come back.
        array_unshift($options, [
          'label' => $this->t('Any'),
          'value' => array_intersect($values, $defaults) ? $key . ':' . self::ANY : '',
          'count' => NULL,
          'checked' => !array_intersect($values, $refines[$key] ?? []),
        ]);
        $groups[] = ['title' => $this->t($title), 'name' => 'refine[' . $key . '_' . count($groups) . ']', 'options' => $options, 'single' => TRUE];
      }
      elseif ($options) {
        $groups[] = ['title' => $this->t($title), 'name' => 'refine[]', 'options' => $options];
      }
    }
    if (!empty($definition['year_filter'])) {
      $groups[] = ['title' => $this->t('Year published'), 'years' => $years];
    }
    return $groups;
  }

  /**
   * The chosen filters as links that remove them. Removing a source also
   * removes its refinements.
   */
  private function chips(string $mode_key, string $query, array $chosen, array $refines, array $years, string $sort): array {
    $chips = [];
    foreach ($chosen as $key) {
      $without = array_diff_key($refines, [$key => TRUE]);
      $chips[] = [
        'label' => SearchSources::get($key)['label'],
        'url' => $this->address($mode_key, $query, array_values(array_diff($chosen, [$key])), $without, $sort, !empty(SearchSources::get($key)['year_filter']) ? [] : $years),
      ];
    }
    $defaults = SearchSources::modes()[$mode_key]['refine_defaults'] ?? [];
    foreach ($refines as $key => $options) {
      foreach ($options as $option) {
        if ($option === self::ANY) {
          continue;
        }
        $without = $refines;
        $without[$key] = array_values(array_diff($options, [$option]));
        if (in_array($option, $defaults[$key] ?? [], TRUE)) {
          $without[$key][] = self::ANY;
        }
        $chips[] = [
          'label' => str_starts_with($option, SearchSources::SERIES) ? substr($option, strlen(SearchSources::SERIES)) : SearchSources::get($key)['refine_options'][$option],
          'url' => $this->address($mode_key, $query, $chosen, array_filter($without), $sort, $years),
        ];
      }
    }
    if ($years) {
      $chips[] = [
        'label' => match (TRUE) {
          !isset($years['to']) => $this->t('From @year', ['@year' => $years['from']]),
          !isset($years['from']) => $this->t('Up to @year', ['@year' => $years['to']]),
          default => $years['from'] . '–' . $years['to'],
        },
        'url' => $this->address($mode_key, $query, $chosen, $refines, $sort),
      ];
    }
    return $chips;
  }

  /**
   * The service behind a source and others to try, each with this search, or NULL.
   */
  private function via(array $definition, string $query): ?array {
    if (empty($definition['via'])) {
      return NULL;
    }
    return [
      'label' => $definition['via'],
      'url' => SearchSources::moreUrl($definition, $query),
      'also' => array_map(static function (string $key) use ($query): array {
        $other = SearchSources::get($key);
        return ['label' => $other['label'], 'url' => SearchSources::moreUrl($other, $query)];
      }, $definition['also'] ?? []),
    ];
  }

  /**
   * Links that search elsewhere.
   */
  private function outsideLinks(array $keys, string $query): array {
    $links = [];
    foreach ($keys as $key) {
      $definition = SearchSources::get($key);
      $links[] = [
        'label' => $definition['more_label'],
        'url' => SearchSources::moreUrl($definition, $query),
      ];
    }
    return $links;
  }

  /**
   * An address on this page. Empty values stay out of it.
   */
  private function address(string $mode, string $query, array $sources = [], array $refines = [], string $sort = 'relevance', array $years = []): string {
    $refine = [];
    foreach ($refines as $key => $options) {
      foreach ($options as $option) {
        $refine[] = $key . ':' . $option;
      }
    }
    $parameters = array_filter([
      'q' => $query,
      'mode' => $mode === 'all' ? '' : $mode,
      'source' => $sources,
      'refine' => $refine,
      'sort' => $sort === 'relevance' ? '' : $sort,
      ...$years,
    ], static fn($value): bool => $value !== '' && $value !== []);
    return Url::fromRoute('palefire_search.page', [], ['query' => $parameters])->toString();
  }

  /**
   * The search, from q or the old form's field name.
   */
  private function query(array $parameters): string {
    $query = $parameters['q'] ?? $parameters['search_api_fulltext_bento'] ?? '';
    return is_string($query) ? mb_substr(trim($query), 0, self::MAX_QUERY) : '';
  }

  /**
   * Keeps a service's good answer for a while, and a failure not at all.
   */
  private function applyExternalCaching(Response $response, bool $failed): void {
    if ($failed) {
      $this->killSwitch->trigger();
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
      return;
    }
    $response->setPublic();
    $response->setMaxAge(self::EXTERNAL_MAX_AGE);
  }

}

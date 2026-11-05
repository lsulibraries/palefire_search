<?php

namespace Drupal\palefire_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\palefire_search\ExternalSearch;
use Drupal\palefire_search\LocalSearch;
use Drupal\palefire_search\SearchSources;
use Drupal\palefire_search\SearchTerms;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
 * source, so "Oral History" never leaves unrelated news in the list.
 */
class SearchController extends ControllerBase {

  /**
   * Results per section in a sections layout.
   */
  private const SECTION_LIMIT = 3;

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

  public function __construct(
    private readonly LocalSearch $local,
    private readonly ExternalSearch $external,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RendererInterface $renderer,
    private readonly KillSwitch $killSwitch,
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
    );
  }

  /**
   * The page title.
   */
  public function title(): string {
    return (string) $this->t('Index');
  }

  /**
   * The page.
   */
  public function page(Request $request): array {
    $parameters = $request->query->all();
    $query = $this->query($parameters);
    $modes = SearchSources::modes();
    $mode_key = is_string($parameters['mode'] ?? NULL) && isset($modes[$parameters['mode']]) ? $parameters['mode'] : 'all';
    $mode = $modes[$mode_key];

    [$chosen, $refines] = $this->selection($parameters, $mode);
    $selected = array_values(array_intersect($mode['sources'], array_unique([...$chosen, ...array_keys($refines)])));
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
        'library' => ['palefire/index-search'],
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

    if ($query === '') {
      return $build;
    }

    $build['#links'] = $this->outsideLinks($mode['links'], $query);
    if (!SearchSources::indexableWords($query)) {
      $build['#notice'] = SearchTerms::hasOperators($query)
        ? $this->t('The site index needs a word of three letters or more to look for, so its results are left out. Outside services still search for “@query”.', ['@query' => $query])
        : $this->t('The site index only matches words of three letters or more, so its results are left out. Outside services still search for “@query”.', ['@query' => $query]);
    }

    // The results first: they record counts the filters can then reuse.
    if (count($selected) === 1 || $mode['layout'] === 'merged') {
      [$build['#list'], $total] = $this->fullList($mode, $selected, $refines, $query, $page, $sort);
      $build['#total'] = $total;
      if (count($sorts) > 1) {
        $build['#sort'] = $sort;
        $build['#sorts'] = array_map(fn(string $label): mixed => $this->t($label), $sorts);
      }
    }
    else {
      $build['#sections'] = $this->sections($selected ?: $mode['sources'], $mode_key, $query, $refines);
    }

    $build['#filters'] = $this->filters($mode, $selected, $chosen, $refines, $query);
    $build['#chips'] = $this->chips($mode_key, $query, $chosen, $refines, $sort);
    $build['#clear_url'] = $this->address($mode_key, $query);
    $build['#selected_count'] = count($chosen) + array_sum(array_map('count', $refines));
    return $build;
  }

  /**
   * One external source's section, as an HTML fragment.
   */
  public function source(Request $request, string $source): Response {
    $definition = SearchSources::get($source);
    if (($definition['kind'] ?? '') !== 'external') {
      throw new NotFoundHttpException();
    }
    $query = $this->query($request->query->all());
    $results = $query === ''
      ? ['total' => 0, 'failed' => FALSE, 'items' => []]
      : $this->external->search($definition, $query, 0, self::SECTION_LIMIT);

    $list = $this->listBuild($definition['label'], $results, SearchSources::moreUrl($definition, $query), $definition['more_label'] ?? NULL);
    $response = new Response((string) $this->renderer->renderInIsolation($list));
    $this->applyExternalCaching($response, $results['failed'] ?? FALSE);
    return $response;
  }

  /**
   * What's chosen in the address, keeping only what this mode offers.
   *
   * @return array
   *   The chosen sources, and the refinements keyed by source.
   */
  private function selection(array $parameters, array $mode): array {
    $chosen = array_values(array_intersect($mode['sources'], array_filter((array) ($parameters['source'] ?? []), 'is_string')));
    $refines = [];
    foreach (array_filter((array) ($parameters['refine'] ?? []), 'is_string') as $value) {
      [$key, $option] = array_pad(explode(':', $value, 2), 2, '');
      if (in_array($key, $mode['sources'], TRUE) && isset(SearchSources::get($key)['refine_options'][$option])) {
        $refines[$key][] = $option;
      }
    }
    return [$chosen, array_map('array_unique', $refines)];
  }

  /**
   * The labelled sections. External ones load after the page.
   *
   * A section with no results is left out, so visitors see what's there.
   */
  private function sections(array $keys, string $mode_key, string $query, array $refines): array {
    $sections = [];
    foreach ($keys as $key) {
      $definition = SearchSources::get($key);
      if ($definition['kind'] === 'external') {
        $sections[] = [
          'key' => $key,
          'label' => $definition['label'],
          'late_url' => Url::fromRoute('palefire_search.source', ['source' => $key], ['query' => ['q' => $query]])->toString(),
          'full_url' => $this->address($mode_key, $query, [$key]),
        ];
        continue;
      }
      $results = $this->local->search([$key], $query, $refines, 0, self::SECTION_LIMIT);
      if ($results['total'] > 0) {
        $more = $results['total'] > self::SECTION_LIMIT ? $this->address($mode_key, $query, [$key]) : NULL;
        $sections[] = [
          'key' => $key,
          'label' => $definition['label'],
          'list' => $this->listBuild($definition['label'], $results, $more, 'Show all @total'),
        ];
      }
    }
    return $sections;
  }

  /**
   * The one list: a chosen source, or a merged mode's sources.
   *
   * @return array
   *   The list's render array, and its total (NULL if it couldn't be reached).
   */
  private function fullList(array $mode, array $selected, array $refines, string $query, int $page, string $sort): array {
    $source = count($selected) === 1 ? SearchSources::get($selected[0]) : NULL;
    if ($source && $source['kind'] === 'external') {
      $results = $this->external->search($source, $query, $page, self::LIST_LIMIT, $sort);
      if ($results['failed']) {
        // Never keep a failure: the next visitor should get a fresh try.
        $this->killSwitch->trigger();
      }
      $list = $this->listBuild($source['label'], $results, SearchSources::moreUrl($source, $query), $source['more_label'] ?? NULL, $page);
      $list['#cache'] = ['max-age' => $results['failed'] ? 0 : self::EXTERNAL_MAX_AGE];
      return [$list, $results['total']];
    }
    $keys = $selected ?: array_values(array_filter($mode['sources'], static fn(string $key): bool => SearchSources::get($key)['kind'] === 'local'));
    $results = $this->local->search($keys, $query, $refines, $page, self::LIST_LIMIT, $sort);
    $label = $source['label'] ?? (string) $this->t('Results');
    $more = $source ? SearchSources::moreUrl($source, $query) : NULL;
    return [$this->listBuild($label, $results, $more, $source['more_label'] ?? NULL, $page), $results['total']];
  }

  /**
   * A results list's render array.
   */
  private function listBuild(string $label, array $results, ?string $more_url, ?string $more_label, ?int $page = NULL): array {
    $build = [
      '#theme' => 'palefire_search_list',
      '#label' => $label,
      '#total' => $results['total'],
      '#items' => $results['items'],
      '#failed' => $results['failed'] ?? FALSE,
      '#simplified' => $results['simplified'] ?? NULL,
      '#more_url' => $more_url,
      '#more_label' => $more_url && $more_label ? $this->t($more_label, ['@total' => number_format((int) $results['total'])]) : NULL,
    ];
    if ($page !== NULL && $results['total'] > self::LIST_LIMIT) {
      $this->pagerManager->createPager($results['total'], self::LIST_LIMIT);
      $build['#pager'] = ['#type' => 'pager'];
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
   * add. Options with nothing to add are left out unless they're chosen.
   * Outside services have no count, since one would mean calling them.
   */
  private function filters(array $mode, array $selected, array $chosen, array $refines, string $query): array {
    $options = [];
    foreach ($mode['sources'] as $key) {
      $definition = SearchSources::get($key);
      $count = $definition['kind'] === 'local' ? $this->local->count([$key], $query) : NULL;
      // Ticked only if chosen. A record type limits results to its source without
      // ticking it, or the form would send that back as a choice of its own.
      $checked = in_array($key, $chosen, TRUE);
      if ($count !== 0 || $checked) {
        $options[] = ['label' => $definition['label'], 'value' => $key, 'count' => $count, 'checked' => $checked];
      }
    }
    $groups = [['title' => $this->t('Source'), 'name' => 'source[]', 'options' => $options]];

    // A source's own filter shows once that source is chosen, or always where
    // the mode leads with it. Each option is a query, so not all at once.
    foreach (array_unique([...($mode['refine_sources'] ?? []), ...$selected]) as $key) {
      $definition = SearchSources::get($key);
      if (!isset($definition['refine_options'])) {
        continue;
      }
      $options = [];
      foreach ($definition['refine_options'] as $value => $label) {
        $value = (string) $value;
        $count = $this->local->count([$key], $query, [$key => [$value]]);
        $checked = in_array($value, $refines[$key] ?? [], TRUE);
        if ($count > 0 || $checked) {
          $options[] = ['label' => $label, 'value' => $key . ':' . $value, 'count' => $count, 'checked' => $checked];
        }
      }
      if ($options) {
        $groups[] = ['title' => $this->t($definition['refine_label']), 'name' => 'refine[]', 'options' => $options];
      }
    }
    return $groups;
  }

  /**
   * The chosen filters as links that remove them. Removing a source also
   * removes its refinements.
   */
  private function chips(string $mode_key, string $query, array $chosen, array $refines, string $sort): array {
    $chips = [];
    foreach ($chosen as $key) {
      $without = array_diff_key($refines, [$key => TRUE]);
      $chips[] = [
        'label' => SearchSources::get($key)['label'],
        'url' => $this->address($mode_key, $query, array_values(array_diff($chosen, [$key])), $without, $sort),
      ];
    }
    foreach ($refines as $key => $options) {
      foreach ($options as $option) {
        $without = $refines;
        $without[$key] = array_values(array_diff($options, [$option]));
        $chips[] = [
          'label' => SearchSources::get($key)['refine_options'][$option],
          'url' => $this->address($mode_key, $query, $chosen, array_filter($without), $sort),
        ];
      }
    }
    return $chips;
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
  private function address(string $mode, string $query, array $sources = [], array $refines = [], string $sort = 'relevance'): string {
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

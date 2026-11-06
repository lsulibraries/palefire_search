<?php

namespace Drupal\palefire_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\palefire_search\ExternalSearch;
use Drupal\palefire_search\LocalSearch;
use Drupal\palefire_search\ResultText;
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
   * Results per section in a sections layout, unless a source sets its own.
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

  /**
   * How long browsers may keep availability, in seconds.
   */
  private const HOLDINGS_MAX_AGE = 120;

  /**
   * The filter value that says no to a filter's default.
   */
  private const ANY = 'any';

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
    $years = array_filter($selected, static fn(string $key): bool => !empty(SearchSources::get($key)['year_filter'])) ? $this->years($parameters) : [];
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
    // A merged list can only merge local sources, so an outside one gets a section.
    $outside = array_filter($selected, static fn(string $key): bool => SearchSources::get($key)['kind'] === 'external');
    if (count($selected) === 1 || ($mode['layout'] === 'merged' && !$outside)) {
      [$build['#list'], $total, $build['#capped']] = $this->fullList($mode, $selected, $refines, $years, $query, $page, $sort);
      $build['#via'] = count($selected) === 1 ? $this->via(SearchSources::get($selected[0]), $query) : NULL;
      $build['#total'] = $total;
      if (count($sorts) > 1) {
        $build['#sort'] = $sort;
        $build['#sorts'] = array_map(fn(string $label): mixed => $this->t($label), $sorts);
      }
      // A source this mode leads with goes above its list, with that source's
      // own defaults. On the first page only: paging is through the list.
      $lead = !$selected && $page === 0 ? ($mode['lead'] ?? []) : [];
      if ($lead) {
        $build['#sections'] = $this->sections($lead, $mode_key, $query, array_intersect_key($mode['refine_defaults'] ?? [], array_flip($lead)), $years);
        $build['#list_title'] = $this->t($mode['list_label']);
        $build['#list_source'] = array_values(array_diff($mode['sources'], $lead))[0];
      }
    }
    else {
      $build['#sections'] = $this->sections($selected ?: $mode['sources'], $mode_key, $query, $refines, $years);
    }

    $build['#filters'] = $this->filters($mode, $selected, $chosen, $refines, $years, $query);
    $build['#chips'] = $this->chips($mode_key, $query, $chosen, $refines, $years, $sort);
    $build['#clear_url'] = $this->address($mode_key, $query);
    $build['#selected_count'] = count($chosen) + array_sum(array_map(static fn(array $options): int => count(array_diff($options, [self::ANY])), $refines)) + ($years ? 1 : 0);
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
    $parameters = $request->query->all();
    $query = $this->query($parameters);
    $refines = $this->selection($parameters, ['sources' => [$source]])[1];
    $years = !empty($definition['year_filter']) ? $this->years($parameters) : [];
    $limit = $definition['section_limit'] ?? self::SECTION_LIMIT;
    $results = $query === ''
      ? ['total' => 0, 'failed' => FALSE, 'items' => []]
      : $this->external->search($definition, $query, 0, $limit, 'relevance', ['refine' => $refines[$source] ?? [], ...$years]);

    $more_url = SearchSources::moreUrl($definition, $query);
    $more_label = $definition['more_label'] ?? NULL;
    // A source with its own list here sends people there. When it found nothing
    // or failed, its own site is still worth offering.
    if (!empty($definition['lists_here']) && !empty($results['items'])) {
      $modes = SearchSources::modes();
      $mode_key = is_string($parameters['mode'] ?? NULL) && isset($modes[$parameters['mode']]) ? $parameters['mode'] : 'all';
      $more_url = $results['total'] > $limit ? $this->address($mode_key, $query, [$source], $refines, 'relevance', $years) : NULL;
      $more_label = 'Show all @total';
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
    $ids = $request->query->all()['ids'] ?? NULL;
    $ids = is_string($ids) ? array_filter(explode(',', $ids), static fn(string $id): bool => (bool) preg_match('/^\d{1,10}$/', $id)) : [];
    $holdings = $this->external->catalogHoldings(array_slice(array_values(array_unique($ids)), 0, self::LIST_LIMIT));

    $build = ['#theme' => 'palefire_search_holdings', '#records' => $holdings ?? []];
    $response = new Response((string) $this->renderer->renderInIsolation($build));
    if ($holdings === NULL) {
      $this->applyExternalCaching($response, TRUE);
      return $response;
    }
    $response->setPublic();
    $response->setMaxAge(self::HOLDINGS_MAX_AGE);
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
      $allowed = isset(SearchSources::get($key)['refine_options'][$option]) || ($option === self::ANY && isset($mode['refine_defaults'][$key]));
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
   * A section with no results is left out, so visitors see what's there.
   */
  private function sections(array $keys, string $mode_key, string $query, array $refines, array $years): array {
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
            ]),
          ])->toString(),
          'full_url' => $this->address($mode_key, $query, [$key], array_intersect_key($refines, [$key => TRUE]), 'relevance', !empty($definition['year_filter']) ? $years : []),
        ];
        continue;
      }
      $limit = $definition['section_limit'] ?? self::SECTION_LIMIT;
      $results = $this->local->search([$key], $query, $refines, 0, $limit);
      if ($results['total'] > 0) {
        $more = $results['total'] > $limit ? $this->address($mode_key, $query, [$key]) : NULL;
        $sections[] = [
          'key' => $key,
          'label' => $definition['label'],
          'about' => $definition['about'] ?? NULL,
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
   *   The list's render array, its total (NULL if it couldn't be reached), and
   *   whether there may be more than that total.
   */
  private function fullList(array $mode, array $selected, array $refines, array $years, string $query, int $page, string $sort): array {
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
    $keys = $selected ?: array_values(array_filter($mode['sources'], static fn(string $key): bool => SearchSources::get($key)['kind'] === 'local'));
    $results = $this->local->search($keys, $query, $refines, $page, self::LIST_LIMIT, $sort);
    $label = $source['label'] ?? (string) $this->t('Results');
    $more = $source ? SearchSources::moreUrl($source, $query) : NULL;
    return [$this->listBuild($label, $results, $more, $source['more_label'] ?? NULL, $page), $results['total'], FALSE];
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
      '#items' => $results['items'],
      '#failed' => $results['failed'] ?? FALSE,
      '#simplified' => $results['simplified'] ?? NULL,
      '#backup' => !empty($results['backup']),
      '#more_url' => $more_url,
      '#more_label' => $more_url && $more_label ? $this->t($more_label, ['@total' => number_format((int) $results['total']) . (!empty($results['capped']) ? '+' : '')]) : NULL,
    ];
    $held = array_filter(array_column($results['items'], 'holdings_id'));
    if ($held) {
      $build['#holdings_url'] = Url::fromRoute('palefire_search.holdings', [], ['query' => ['ids' => implode(',', $held)]])->toString();
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
      if ($options) {
        $groups[] = ['title' => $this->t($definition['refine_label']), 'name' => 'refine[]', 'options' => $options];
      }
    }
    return $groups;
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
          'label' => SearchSources::get($key)['refine_options'][$option],
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

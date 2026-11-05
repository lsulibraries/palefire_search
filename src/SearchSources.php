<?php

namespace Drupal\palefire_search;

/**
 * What the Index searches, and which modes show what.
 *
 * Every source is defined here and nowhere else. Keys appear in addresses
 * (?source=articles), so they name what a visitor finds rather than a vendor
 * or a View: when Discovery moves to Alma, only the source's definition and
 * its adapter in ExternalSearch change.
 *
 * Kinds:
 * - local: a filter on the Search API index. "types" limits the bundle,
 *   "categories" and "not_categories" limit field_category (taxonomy IDs,
 *   copied from the bento_* Views), "article_categories" limits
 *   field_article_category.
 * - external: a View whose query plugin calls an outside service.
 * - link: no results here, only a link to search elsewhere.
 */
final class SearchSources {

  /**
   * The Search API index holding the local sources.
   */
  public const INDEX = 'bento_search';

  /**
   * Words shorter than this are not indexed (the server's min_chars).
   */
  public const MIN_WORD = 3;

  /**
   * Fields kept out of searches: staff-only text a visitor must not match.
   */
  public const PRIVATE_FIELDS = ['field_internal_note', 'field_interviewee_release'];

  /**
   * Sorts for a source with real dates.
   *
   * Research Guides offers none: its connector only searches full text when
   * sorted by relevance, so another sort would change what's found.
   */
  public const DATE_SORTS = [
    'relevance' => 'Relevance',
    'newest' => 'Newest first',
    'oldest' => 'Oldest first',
  ];

  /**
   * The modes, in tab order.
   *
   * Sections: one labelled block per source, because their results can't be
   * ranked against each other. Merged: one ranked list from one query.
   */
  public static function modes(): array {
    return [
      'all' => [
        'label' => 'All',
        'layout' => 'sections',
        'sources' => ['articles', 'databases', 'guides', 'archive', 'pages', 'faq', 'etextbooks', 'news', 'gear', 'staff'],
        'links' => ['repository', 'catalog'],
      ],
      'research' => [
        'label' => 'Research',
        'layout' => 'sections',
        'sources' => ['articles', 'databases', 'etextbooks', 'guides', 'archive'],
        'links' => ['repository', 'catalog'],
      ],
      'website' => [
        'label' => 'Website',
        'layout' => 'merged',
        'sources' => ['pages', 'faq', 'gear', 'news', 'staff'],
        'links' => [],
      ],
      'special' => [
        'label' => 'Special Collections',
        'layout' => 'merged',
        'sources' => ['archive', 'news_special'],
        // Record type is this mode's main filter, so it's always offered.
        'refine_sources' => ['archive'],
        'links' => [],
      ],
    ];
  }

  /**
   * The sources.
   */
  public static function all(): array {
    return [
      'articles' => [
        'label' => 'Articles & Books',
        'kind' => 'external',
        'adapter' => 'discovery',
        // It leads the page, and it's the widest source, so it shows the most.
        'section_limit' => 5,
        // Its full list is on this page, with filters: go there, not away.
        'lists_here' => TRUE,
        'sorts' => self::DATE_SORTS,
        'sort_values' => ['relevance' => 'relevance', 'newest' => 'date', 'oldest' => 'date2'],
        'refine_options' => [
          'full_text' => 'Full text online',
          'peer_reviewed' => 'Peer reviewed',
          'journals' => 'Academic journals',
          'books' => 'Books',
          'ebooks' => 'E-books',
          'magazines' => 'Magazines',
          'news' => 'News',
          'reviews' => 'Reviews',
          'trade' => 'Trade publications',
          'reports' => 'Reports',
          'biographies' => 'Biographies',
          'primary_sources' => 'Primary sources',
          'dissertations' => 'Dissertations',
          'conferences' => 'Conference materials',
        ],
        'refine_groups' => [
          'Limit to' => ['full_text', 'peer_reviewed'],
          'Type' => ['journals', 'books', 'ebooks', 'magazines', 'news', 'reviews', 'trade', 'reports', 'biographies', 'primary_sources', 'dissertations', 'conferences'],
        ],
        'year_filter' => TRUE,
        // Named beside the source's heading, with its own site and others to try.
        'via' => 'Discovery',
        'also' => ['catalog'],
        'more_label' => 'See all in Discovery',
        'more_url' => 'https://libezp.lib.lsu.edu/login?url=https://search.ebscohost.com/login.aspx?direct=true&site=eds-live&scope=site&type=0&mode=and&lang=en&bquery=@q',
      ],
      'guides' => [
        'label' => 'Research Guides',
        'kind' => 'external',
        'view' => 'bento_libguides_fulltext',
        'display' => 'guidestext',
        'adapter' => 'guides',
        'more_label' => 'See all research guides',
        'more_url' => 'https://guides.lib.lsu.edu/srch.php?q=@q',
      ],
      'repository' => [
        'label' => 'LSU Scholarship',
        'kind' => 'link',
        'more_label' => 'Search the Scholarly Repository',
        'more_url' => 'https://repository.lsu.edu/do/search/?q=@q&start=0&context=8403704',
      ],
      'catalog' => [
        'label' => 'Catalog',
        'kind' => 'link',
        'more_label' => 'Search the Catalog',
        'more_url' => 'https://lsu.ent.sirsi.net/client/en_US/lsu/search/results?user_id=&qu=@q&rt=Keyword&qf=ALL',
      ],
      'databases' => [
        'label' => 'Databases',
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [66],
        'more_label' => 'See all databases',
        'more_url' => 'https://guides.lib.lsu.edu/az.php?q=@q',
      ],
      'etextbooks' => [
        'label' => 'E-Textbooks',
        'kind' => 'local',
        'types' => ['e_books'],
      ],
      'archive' => [
        'label' => 'Archive Records',
        'kind' => 'local',
        'types' => ['archive_record'],
        'refine_field' => 'field_record_type',
        'refine_label' => 'Record type',
        'refine_options' => [
          'finding_aid' => 'Finding Aids',
          'breaux_data' => 'Breaux Data',
          'oral_history' => 'Oral History',
          'louisiana_folklife' => 'Louisiana Folklife',
          'university_archives_record_group' => 'University Archives Record Group',
          'university_archives_finding_aids' => 'University Archives Finding Aids',
          'manuscript_collections_descriptions' => 'Manuscript Collections',
          'louisiana_newspapers' => 'Louisiana Newspapers',
        ],
      ],
      'pages' => [
        'label' => 'Pages',
        'images' => TRUE,
        'kind' => 'local',
        // modular_page isn't in the index yet. Listing it here means Palefire
        // pages appear as soon as it is added, with no change to this module.
        'types' => ['tile', 'modular_page'],
        'not_categories' => [66, 352, 541, 344, 17],
        'refine_field' => 'field_category',
        'refine_label' => 'Category',
        'refine_options' => [
          16 => 'Services',
          342 => 'Policies',
          322 => 'Location',
          27 => 'Collection',
          13 => 'External',
        ],
      ],
      'faq' => [
        'label' => 'Questions & Answers',
        // The answer, ahead of a tile's usual summary.
        'summary_fields' => ['field_tile_linked_text', 'field_tile_long_summary', 'field_tile_text'],
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [541],
      ],
      'gear' => [
        'label' => 'Gear',
        'images' => TRUE,
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [17],
      ],
      'news' => [
        'label' => 'News and Notes',
        'images' => TRUE,
        'kind' => 'local',
        'types' => ['article'],
        'sorts' => self::DATE_SORTS,
        'sort_field' => 'field_article_date',
        'refine_field' => 'field_article_category',
        'refine_label' => 'Category',
        'refine_options' => [
          176 => 'Announcements',
          183 => 'Digital Collections',
          179 => 'Events',
          181 => 'Exhibitions',
          380 => 'Free People of Color',
          185 => 'Media Center',
          351 => 'Music',
          178 => 'People',
          180 => 'Resources',
          182 => 'Services',
          177 => 'Special Collections',
        ],
      ],
      'news_special' => [
        'label' => 'News and Notes',
        'images' => TRUE,
        'kind' => 'local',
        'types' => ['article'],
        'article_categories' => [177],
        'sorts' => self::DATE_SORTS,
        'sort_field' => 'field_article_date',
      ],
      'staff' => [
        'label' => 'Staff',
        'images' => TRUE,
        'kind' => 'local',
        'types' => ['staff'],
      ],
    ];
  }

  /**
   * One source, or NULL.
   */
  public static function get(string $key): ?array {
    return self::all()[$key] ?? NULL;
  }

  /**
   * The sorts on offer: only when one source is chosen, because a mixed list
   * has items with no date to sort by.
   */
  public static function sorts(array $selected): array {
    return count($selected) === 1 ? (self::get($selected[0])['sorts'] ?? []) : [];
  }

  /**
   * The words of a search that the index can match.
   */
  public static function indexableWords(string $query): array {
    return array_merge(...array_map(self::indexableWordsIn(...), ['', ...SearchTerms::wanted($query)]));
  }

  /**
   * The words the index can match in one word or phrase of a search.
   */
  public static function indexableWordsIn(string $text): array {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($words, static fn(string $word): bool => mb_strlen($word) >= self::MIN_WORD));
  }

  /**
   * A source's outside address for this search, or NULL.
   */
  public static function moreUrl(array $source, string $query): ?string {
    return isset($source['more_url']) ? str_replace('@q', rawurlencode($query), $source['more_url']) : NULL;
  }

}

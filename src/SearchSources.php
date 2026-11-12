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
 * - upcoming: not searched yet. A mode's "upcoming" list offers it as a disabled
 *   Source option with its definition, and it can't be chosen.
 */
final class SearchSources {

  /**
   * The Search API index holding the local sources.
   */
  public const INDEX = 'bento_search';

  /**
   * Words shorter than this are not indexed (the server's min_chars).
   */
  public const MIN_WORD = 2;

  /**
   * Fields kept out of searches: staff-only text a visitor must not match.
   */
  public const PRIVATE_FIELDS = ['field_internal_note', 'field_interviewee_release'];

  /**
   * The index fields each searchable field means, for author:, title: and
   * subject:. The index has no author for books or finding aids, so author:
   * finds oral histories by the people interviewed or interviewing. A field
   * missing from the index is skipped.
   */
  /**
   * How a series is written as a filter's option: oral_histories:series:University
   * History.
   */
  public const SERIES = 'series:';

  public const INDEX_FIELDS = [
    'author' => ['field_interviewee', 'field_interviewer', 'field_principal_interviewers', 'field_principal_interviewers_1'],
    'title' => ['title', 'field_title', 'field_book_title', 'field_course_title', 'field_collection_name'],
    'subject' => ['field_topics', 'field_manuscript_topic', 'field_search_tags'],
  ];

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
        'sources' => ['articles', 'catalog', 'databases', 'guides', 'repository', 'archive', 'oral_histories', 'pages', 'faq', 'etextbooks', 'news', 'gear', 'staff'],
        'upcoming' => ['alma'],
        'links' => [],
      ],
      'research' => [
        'label' => 'Research',
        'layout' => 'sections',
        'sources' => ['catalog', 'articles', 'journals', 'databases', 'etextbooks', 'guides', 'repository', 'archive', 'oral_histories'],
        'upcoming' => ['alma'],
        'links' => [],
      ],
      'special' => [
        'label' => 'Special Collections',
        'layout' => 'merged',
        'sources' => ['catalog', 'special_guides', 'archive', 'oral_histories'],
        // Sections above the list until a source is chosen: a merged list can't
        // hold an outside source, and oral histories are their own kind of
        // thing. list_label names the list under them, which pages through
        // what's left: the finding aids.
        'lead' => ['catalog', 'special_guides', 'oral_histories'],
        'list_label' => 'PDF Finding Aids',
        // Filters already chosen when a source is, which can still be changed.
        'refine_defaults' => ['catalog' => ['library_special']],
        // With the Catalog's Special Collections filter taken off, and none of
        // its archives chosen, the search is a Research one: it moves
        // there with everything else it had.
        'unless' => [
          'refine' => ['catalog' => 'library_special'],
          'sources' => ['archive', 'oral_histories', 'special_guides'],
          'mode' => 'research',
        ],
        'upcoming' => ['alma'],
        'links' => [],
      ],
      'website' => [
        'label' => 'Website',
        'layout' => 'merged',
        'sources' => ['pages', 'faq', 'gear', 'news', 'staff'],
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
        'label' => 'Discovery',
        'about' => 'Articles, books, e-books and more from across the Libraries’ subscriptions and collections, searched at once. A good place to start.',
        'kind' => 'external',
        'adapter' => 'discovery',
        // It's the widest source, so it shows the most.
        'section_limit' => 5,
        // Its full list is on this page, with filters: go there, not away.
        'lists_here' => TRUE,
        'sorts' => self::DATE_SORTS,
        'sort_values' => ['relevance' => 'relevance', 'newest' => 'date', 'oldest' => 'date2'],
        'refine_options' => [
          'full_text' => 'Full text online',
          'peer_reviewed' => 'Peer reviewed',
          'in_collection' => 'In the Libraries’ collection',
          'epub' => 'EPUB e-books',
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
          'language_english' => 'English',
          'language_french' => 'French',
          'language_german' => 'German',
          'language_spanish' => 'Spanish',
          'language_latin' => 'Latin',
          'language_italian' => 'Italian',
          'language_dutch' => 'Dutch',
          'language_portuguese' => 'Portuguese',
          'language_russian' => 'Russian',
          'language_chinese' => 'Chinese',
          'language_japanese' => 'Japanese',
          'language_arabic' => 'Arabic',
        ],
        'refine_groups' => [
          'Limit to' => ['full_text', 'peer_reviewed', 'in_collection', 'epub'],
          'Type' => ['journals', 'books', 'ebooks', 'magazines', 'news', 'reviews', 'trade', 'reports', 'biographies', 'primary_sources', 'dissertations', 'conferences'],
          'Language' => ['language_english', 'language_french', 'language_german', 'language_spanish', 'language_latin', 'language_italian', 'language_dutch', 'language_portuguese', 'language_russian', 'language_chinese', 'language_japanese', 'language_arabic'],
        ],
        'year_filter' => TRUE,
        // Named beside the source's heading, with its own site and others to try.
        'via' => 'Discovery',
        'also' => ['catalog'],
        'more_label' => 'See all in Discovery',
        'more_url' => 'https://libezp.lib.lsu.edu/login?url=https://search.ebscohost.com/login.aspx?direct=true&site=eds-live&scope=site&type=0&mode=and&lang=en&bquery=@q',
      ],
      // The guides site's own search, with the View as a stand-in when it
      // can't be read (ExternalSearch::guides()).
      'guides' => [
        'label' => 'Research Guides',
        'about' => 'Librarians’ pages of recommended sources and tips for a subject or a course.',
        'kind' => 'external',
        'view' => 'bento_libguides_fulltext',
        'display' => 'guidestext',
        'adapter' => 'guides',
        'more_label' => 'See all research guides',
        'more_url' => 'https://guides.lib.lsu.edu/srch.php?q=@q',
      ],
      // The research guides tagged Special Collections, from the guides site's
      // own search (GuideSearch), so a guide joins as soon as it's tagged.
      'special_guides' => [
        'label' => 'Special Collections Guides',
        'about' => 'Librarians’ guides to Special Collections’ holdings and to researching a subject in them: a subset of Research Guides.',
        'kind' => 'external',
        'adapter' => 'guides',
        // The guides site's ID for its Special Collections tag.
        'tag_id' => 536193,
        // Its full list is on this page: go there, not away.
        'lists_here' => TRUE,
        'more_label' => 'See all Special Collections guides',
        'more_url' => 'https://guides.lib.lsu.edu/srch.php?q=@q&tag=Special+Collections&default_lg=1',
      ],
      // The repository's records, copied here nightly over OAI-PMH
      // (RepositoryHarvest), because its search can't be asked from this
      // server. The copy has no text from the files, so the note and the "Go
      // to" link send people to the repository's own search for that.
      'repository' => [
        'label' => 'Scholarly Repository',
        'about' => 'Theses, dissertations, articles and other work by LSU researchers, searched by title, author, abstract and keywords. To search the full text of each document, use the Scholarly Repository’s own search.',
        'kind' => 'external',
        'adapter' => 'repository',
        // Its full list is on this page: go there, not away.
        'lists_here' => TRUE,
        'sorts' => self::DATE_SORTS,
        // No filters of its own, only the year, which needs these to show.
        'refine_options' => [],
        'refine_groups' => [],
        'year_filter' => TRUE,
        'via' => 'the Scholarly Repository',
        'more_label' => 'Search the Scholarly Repository',
        'more_url' => 'https://repository.lsu.edu/do/search/?q=@q&start=0&context=8403704',
      ],
      'catalog' => [
        'label' => 'Catalog',
        'about' => 'What the Libraries hold: print and online books, journals, microforms, maps, music, films and archives, with call numbers and where to find them.',
        'kind' => 'external',
        'adapter' => 'catalog',
        'section_limit' => 5,
        // Its full list is on this page: go there, not away.
        'lists_here' => TRUE,
        // The service takes one choice to a filter.
        'refine_single' => TRUE,
        'refine_options' => [
          'type_book' => 'Books',
          'type_archive' => 'Archives and manuscripts',
          'type_online' => 'Online resources',
          'type_microform' => 'Microforms',
          'type_av' => 'Audio-visual',
          'type_journal' => 'Print journals',
          'type_unbound' => 'Print journals, unbound',
          'type_score' => 'Music scores',
          'type_map' => 'Maps',
          'type_newspaper' => 'Newspapers',
          'type_graphic' => 'Posters and pictures',
          'library_main' => 'LSU Library',
          'library_special' => 'Special Collections',
          'library_documents' => 'Government Documents and Microforms',
          'library_music' => 'Carter Music Resources Center',
          'library_maps' => 'Cartographic Information Center',
          'library_veterinary' => 'Veterinary Medicine Library',
          'language_english' => 'English',
          'language_french' => 'French',
          'language_german' => 'German',
          'language_spanish' => 'Spanish',
          'language_latin' => 'Latin',
          'language_italian' => 'Italian',
          'language_dutch' => 'Dutch',
          'language_portuguese' => 'Portuguese',
          'language_russian' => 'Russian',
          'language_chinese' => 'Chinese',
          'language_japanese' => 'Japanese',
          'language_arabic' => 'Arabic',
        ],
        'refine_groups' => [
          'Type' => ['type_book', 'type_archive', 'type_online', 'type_microform', 'type_av', 'type_journal', 'type_unbound', 'type_score', 'type_map', 'type_newspaper', 'type_graphic'],
          'Library' => ['library_main', 'library_special', 'library_documents', 'library_music', 'library_maps', 'library_veterinary'],
          'Language' => ['language_english', 'language_french', 'language_german', 'language_spanish', 'language_latin', 'language_italian', 'language_dutch', 'language_portuguese', 'language_russian', 'language_chinese', 'language_japanese', 'language_arabic'],
        ],
        'year_filter' => TRUE,
        'more_label' => 'Search the Catalog',
        'more_url' => 'https://lsu.ent.sirsi.net/client/en_US/lsu/search/results?user_id=&qu=@q&rt=Keyword&qf=ALL',
      ],
      'journals' => [
        'label' => 'Journals',
        'about' => 'Journals, newspapers, newsletters and reports the Libraries can read online, with the years each one covers and where to read them.',
        'kind' => 'external',
        'adapter' => 'journals',
        // Its full list is on this page: go there, not away.
        'lists_here' => TRUE,
        'refine_options' => [
          'journal' => 'Journals',
          'newspaper' => 'Newspapers',
          'newsletter' => 'Newsletters',
          'report' => 'Reports',
        ],
        'refine_groups' => [
          'Type' => ['journal', 'newspaper', 'newsletter', 'report'],
        ],
        'more_label' => 'Search all journals',
        'more_url' => 'https://publications.ebsco.com/?custId=s8491974&groupId=main&profileId=pfui&orderBy=relevance&searchtype=contains&searchField=titlename&resourceTypeFacet%5B%5D=journals,newsletters,reports,newspapers&search=@q',
      ],
      'alma' => [
        'label' => 'Alma (coming soon)',
        'about' => 'The Libraries’ next system for finding and requesting what they hold. It isn’t searched here yet.',
        'kind' => 'upcoming',
      ],
      'databases' => [
        'label' => 'Databases',
        'about' => 'Collections the Libraries subscribe to, for searching one subject or kind of source in depth.',
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [66],
        'more_label' => 'See all databases',
        'more_url' => 'https://guides.lib.lsu.edu/az.php?q=@q',
      ],
      'etextbooks' => [
        'label' => 'E-Textbooks',
        'about' => 'Online books the Libraries provide for LSU courses, with the class, section and instructor that use each one.',
        'kind' => 'local',
        'types' => ['e_books'],
      ],
      // Archive records are two sources, one per record type, searched through
      // their PDFs. The rest of the archive records, such as Breaux Data, aren't
      // part of the Index: the index leaves them out once its "Archive records:
      // finding aids and oral histories only" processor is on (ArchivePdfsOnly),
      // and record_types keeps them out either way. record_field names the
      // field record_types are read from.
      'archive' => [
        'label' => 'PDF Finding Aids',
        'about' => 'Special Collections finding aids, searched through the full text of each PDF.',
        'kind' => 'local',
        'types' => ['archive_record'],
        'record_field' => 'field_record_type',
        'record_types' => ['finding_aid'],
        // What each record type is called on its result.
        'record_labels' => [
          'finding_aid' => 'Finding aid',
        ],
      ],
      'oral_histories' => [
        'label' => 'Oral histories',
        'about' => 'Interviews from the T. Harry Williams Center for Oral History, searched through the full text of each abstract PDF.',
        'kind' => 'local',
        'types' => ['archive_record'],
        'record_field' => 'field_record_type',
        'record_types' => ['oral_history'],
        // Records of these types with no PDF are left out: oral histories not
        // yet processed, which are to leave the index.
        'needs_url' => ['oral_history'],
        'record_labels' => [
          'oral_history' => 'Oral history',
        ],
        // Narrowed by series, from a list under the source once it or a series
        // is chosen. Needs the field in the index as a String
        // (LocalSearch::hasSeries()). The series are OralHistorySeries'.
        'series_field' => 'field_series',
      ],
      'pages' => [
        'label' => 'Pages',
        'about' => 'Pages on this website, such as services, places and policies.',
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
        'about' => 'Answers to questions people often ask the Libraries.',
        // The answer, ahead of a tile's usual summary.
        'summary_fields' => ['field_tile_linked_text', 'field_tile_long_summary', 'field_tile_text'],
        // Answers are short: the whole of one, in its paragraphs, not a cut.
        'whole_summary' => TRUE,
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [541],
      ],
      'gear' => [
        'label' => 'Gear',
        'about' => 'Equipment you can borrow from the Libraries.',
        'images' => TRUE,
        'kind' => 'local',
        'types' => ['tile'],
        'categories' => [17],
      ],
      'news' => [
        'label' => 'News and Notes',
        'about' => 'Stories and announcements from the Libraries.',
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
      'staff' => [
        'label' => 'Staff',
        'about' => 'The people who work at the Libraries, and how to reach them.',
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
   * What a record type is called on its result, from the source of that type.
   */
  public static function recordLabel(string $type): string {
    foreach (self::all() as $definition) {
      if (isset($definition['record_labels'][$type])) {
        return $definition['record_labels'][$type];
      }
    }
    return '';
  }

  /**
   * Every record type a source of these node types searches, as field =>
   * record types. What ArchivePdfsOnly keeps in the index.
   */
  public static function recordTypes(string $bundle): array {
    $types = [];
    foreach (self::all() as $definition) {
      if (isset($definition['record_field'], $definition['record_types']) && in_array($bundle, $definition['types'] ?? [], TRUE)) {
        $types[$definition['record_field']] = array_merge($types[$definition['record_field']] ?? [], $definition['record_types']);
      }
    }
    return $types;
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
   *
   * The search goes as that site reads it: fields in Discovery's codes or the
   * Catalog's names. Other sites get it as typed, as before, unless it names
   * a field, which they'd read as a word: then its words only.
   */
  public static function moreUrl(array $source, string $query): ?string {
    if (!isset($source['more_url'])) {
      return NULL;
    }
    $terms = match (TRUE) {
      in_array($source['adapter'] ?? NULL, ['discovery', 'journals'], TRUE) => SearchTerms::forDiscovery($query),
      ($source['adapter'] ?? NULL) === 'catalog' => SearchTerms::forCatalog($query),
      SearchTerms::hasFields($query) => SearchTerms::forGuides($query),
      default => $query,
    };
    return str_replace('@q', rawurlencode($terms === '' ? $query : $terms), $source['more_url']);
  }

}

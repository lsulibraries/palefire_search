<?php

namespace Drupal\palefire_search;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Searches the local sources with direct Search API queries.
 *
 * Node access and published status stay with the index's own processors
 * (content_access, entity_status): the access check is never bypassed here.
 */
class LocalSearch {

  /**
   * Where each kind of content keeps its picture, first filled wins.
   */
  private const IMAGE_FIELDS = [
    'article' => ['field_image'],
    'modular_page' => ['field_image_cover', 'field_exhibit_cover'],
    'staff' => ['field_staff_photo'],
    'tile' => ['field_tile_cover'],
  ];

  /**
   * Image styles for a thumbnail, first that exists wins. Never the original
   * file, which could be many megabytes for a small picture.
   */
  private const IMAGE_STYLES = [
    'staff' => ['pf_staff_thumb', 'medium', 'thumbnail'],
    'default' => ['medium', 'thumbnail'],
  ];

  /**
   * The most results read when merging E-Textbooks. A search matching more
   * still works: what's past this point just isn't merged.
   */
  private const MERGE_LIMIT = 2000;

  /**
   * Counts this request already knows, so a section and its filter share one.
   */
  private array $counts = [];

  /**
   * Search API item IDs of records left out for having no PDF, by type list.
   */
  private array $withoutUrl = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PdfFacts $pdfFacts,
  ) {}

  /**
   * One page of results across these sources.
   *
   * @return array
   *   total, and items: [title, url, external, summary, excerpt, pdf, meta,
   *   source, image, image_url, details]. details is a list with one entry per copy of
   *   an E-Textbook. excerpt: an archive record's words around the match. pdf: [pages, size]
   *   when an archive record links to a PDF on this site. archive_pdf: the
   *   site path of a finding aid's or oral history abstract's PDF.
   */
  public function search(array $source_keys, string $query, array $refines, int $page, int $limit, string $sort = 'relevance'): array {
    if ($this->listsBooks($source_keys)) {
      return $this->searchBooks($source_keys, $query, $refines, $page, $limit, $sort);
    }
    $search = $this->build($source_keys, $query, $refines);
    if (!$search) {
      return ['total' => 0, 'items' => []];
    }
    $this->applySort($search, $source_keys, $sort);
    $search->range($page * $limit, $limit);
    $results = $search->execute();
    $this->counts[$this->countKey($source_keys, $query, $refines)] = (int) $results->getResultCount();

    $words = SearchSources::indexableWords($query);
    $items = [];
    foreach ($results->getResultItems() as $result) {
      $object = $result->getOriginalObject();
      $node = $object ? $object->getValue() : NULL;
      if ($node instanceof NodeInterface) {
        $items[] = $this->row($this->entityRepository->getTranslationFromContext($node), $source_keys, $words, $result->getExcerpt());
      }
    }
    return ['total' => (int) $results->getResultCount(), 'items' => $items];
  }

  /**
   * How many results a source would give, without loading any.
   */
  public function count(array $source_keys, string $query, array $refines = []): int {
    $key = $this->countKey($source_keys, $query, $refines);
    if (isset($this->counts[$key])) {
      return $this->counts[$key];
    }
    if ($this->listsBooks($source_keys)) {
      return $this->searchBooks($source_keys, $query, $refines, 0, 0)['total'];
    }
    $search = $this->build($source_keys, $query, $refines);
    if (!$search) {
      return 0;
    }
    $search->range(0, 0);
    return $this->counts[$key] = (int) $search->execute()->getResultCount();
  }

  /**
   * Whether any of these sources holds E-Textbooks.
   */
  private function listsBooks(array $source_keys): bool {
    foreach ($source_keys as $key) {
      if (in_array('e_books', SearchSources::get($key)['types'] ?? [], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * One page of results with each E-Textbook once.
   *
   * Every class that uses a book has its own node, all with the same link. The
   * nodes and the index stay as they are: copies are only merged here, so all
   * matches are read first for the total and the pages to come out right.
   */
  private function searchBooks(array $source_keys, string $query, array $refines, int $page, int $limit, string $sort = 'relevance'): array {
    $search = $this->build($source_keys, $query, $refines);
    if (!$search) {
      return ['total' => 0, 'items' => []];
    }
    $this->applySort($search, $source_keys, $sort);
    $search->range(0, self::MERGE_LIMIT);
    $results = $search->execute();
    $found = $results->getResultItems();

    $ids = [];
    foreach (array_keys($found) as $item_id) {
      if (preg_match('#/(\d+)(?::|$)#', (string) $item_id, $match)) {
        $ids[$item_id] = (int) $match[1];
      }
    }
    $addresses = $this->bookAddresses($ids);

    // Keys are strings with a prefix, so the order of first appearance holds.
    $groups = [];
    foreach ($found as $item_id => $result) {
      $address = $addresses[$ids[$item_id] ?? 0] ?? NULL;
      $groups[$address !== NULL ? 'book:' . $address : 'item:' . $item_id][] = $result;
    }
    $total = count($groups) + max(0, (int) $results->getResultCount() - count($found));
    $this->counts[$this->countKey($source_keys, $query, $refines)] = $total;

    $groups = array_slice($groups, $page * $limit, $limit, TRUE);
    $copies = $this->bookCopies(array_keys($groups));
    $words = SearchSources::indexableWords($query);
    $items = [];
    foreach ($groups as $key => $group) {
      $matched = [];
      foreach ($group as $result) {
        $object = $result->getOriginalObject();
        $node = $object ? $object->getValue() : NULL;
        if ($node instanceof NodeInterface) {
          $matched[$node->id()] = $this->entityRepository->getTranslationFromContext($node);
        }
      }
      if ($matched) {
        // The copies that matched come first, so the row names the class searched for.
        $items[] = $this->mergedRow($matched + ($copies[$key] ?? []), $source_keys, $words);
      }
    }
    return ['total' => $total, 'items' => $items];
  }

  /**
   * Which address each E-Textbook among these nodes links to.
   *
   * @param int[] $ids
   *   Node ids.
   *
   * @return string[]
   *   Addresses by node id. Other kinds of content have none.
   */
  private function bookAddresses(array $ids): array {
    if (!$ids) {
      return [];
    }
    $addresses = [];
    foreach ($this->bookLinks('entity_id', array_values(array_unique($ids))) as $id => $value) {
      $address = $this->bookAddress($value);
      if ($address !== NULL) {
        $addresses[$id] = $address;
      }
    }
    return $addresses;
  }

  /**
   * Every viewable copy of these books, whether or not it matched the search.
   *
   * @param string[] $keys
   *   Group keys. Only the ones naming a book are looked up.
   *
   * @return array
   *   Nodes by node id, in lists by group key.
   */
  private function bookCopies(array $keys): array {
    $values = [];
    foreach ($keys as $key) {
      if (str_starts_with($key, 'book:')) {
        $address = substr($key, 5);
        array_push($values, $address, 'http://' . $address, 'https://' . $address);
      }
    }
    if (!$values) {
      return [];
    }
    $links = $this->bookLinks('field_book_url_value', $values);
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($links));
    $copies = [];
    foreach ($nodes as $id => $node) {
      if ($node instanceof NodeInterface && $node->isPublished() && $node->access('view')) {
        $copies['book:' . $this->bookAddress($links[$id])][$id] = $this->entityRepository->getTranslationFromContext($node);
      }
    }
    return $copies;
  }

  /**
   * E-Textbook links by node id, from the field's table.
   *
   * Reading the table saves loading every matching node. If it can't be read
   * the copies just aren't merged.
   */
  private function bookLinks(string $column, array $values): array {
    try {
      return \Drupal::database()->select('node__field_book_url', 'link')
        ->fields('link', ['entity_id', 'field_book_url_value'])
        ->condition('link.' . $column, $values, 'IN')
        ->condition('link.deleted', 0)
        ->execute()
        ->fetchAllKeyed();
    }
    catch (\Exception $e) {
      \Drupal::logger('palefire_search')->warning('E-Textbook copies were not merged: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * A book's link without what doesn't change the book, or NULL if not a link.
   */
  private function bookAddress(string $value): ?string {
    $value = trim($value);
    return preg_match('#^(https?://|/)#i', $value) ? rtrim(preg_replace('#^https?://#i', '', $value), '/') : NULL;
  }

  /**
   * One row from one or more copies of a book, or from any other single node.
   */
  private function mergedRow(array $nodes, array $source_keys, array $words): array {
    $first = reset($nodes);
    $row = $this->row($first, $source_keys, $words);
    if ($first->bundle() !== 'e_books') {
      return $row;
    }

    $details = $codes = $terms = $titles = [];
    foreach ($nodes as $node) {
      $copy = $this->bookDetails($node);
      $details[json_encode($copy)] = $copy;
      $codes[] = ResultText::clean($this->text($node, 'field_course_code'));
      $terms[] = $copy['term'];
      $titles[] = ResultText::clean($this->text($node, 'field_course_title'));
      $row['image_url'] = $row['image_url'] ?: ResultText::imageUrl($this->text($node, 'field_book_image_url'));
    }
    $row['details'] = array_values($details);
    $row['meta'] = ResultText::clean(implode(' · ', array_filter([
      $this->text($first, 'field_book_author'),
      $this->firstAndMore($codes),
      $this->firstAndMore($terms),
    ])));
    // A book used in different classes has no one course to name.
    if (count(array_unique($titles)) > 1) {
      $row['summary'] = '';
    }
    return $row;
  }

  /**
   * The first different value, and how many more there are: HIST 7922 + 2 more.
   */
  private function firstAndMore(array $values): string {
    $values = array_values(array_unique(array_filter($values)));
    return match (count($values)) {
      0 => '',
      1 => $values[0],
      default => sprintf('%s + %d more', $values[0], count($values) - 1),
    };
  }

  /**
   * The key for a count this request already knows.
   *
   * A refinement only changes its own source, so only those are in the key.
   */
  private function countKey(array $source_keys, string $query, array $refines): string {
    return json_encode([$source_keys, $query, array_intersect_key($refines, array_flip($source_keys))], JSON_THROW_ON_ERROR);
  }

  /**
   * Sorts a one-source list by its date. Relevance is already set.
   */
  private function applySort(QueryInterface $search, array $source_keys, string $sort): void {
    $field = count($source_keys) === 1 ? (SearchSources::get($source_keys[0])['sort_field'] ?? NULL) : NULL;
    if ($field && $sort !== 'relevance') {
      $sorts = &$search->getSorts();
      $sorts = [
        $field => $sort === 'oldest' ? QueryInterface::SORT_ASC : QueryInterface::SORT_DESC,
        'search_api_id' => QueryInterface::SORT_ASC,
      ];
    }
  }

  /**
   * The query, or NULL when no word in the search can be matched.
   *
   * The server ignores words under three characters, and a query left with no
   * words returns every item in the index. So those never reach the server.
   */
  private function build(array $source_keys, string $query, array $refines): ?QueryInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load(SearchSources::INDEX);
    $keys = SearchTerms::searchKeys($query);
    if (!$index instanceof IndexInterface || !$index->status() || $keys === NULL) {
      return NULL;
    }

    $search = $index->query();
    $parse_mode = \Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('terms');
    $parse_mode->setConjunction('AND');
    $search->setParseMode($parse_mode);
    // The parse mode can't read AND, OR, NOT or groups, so the keys are replaced
    // with SearchTerms' reading of them.
    $search->keys($query);
    $search_keys = &$search->getKeys();
    $search_keys = $keys;
    $search->setFulltextFields(array_values(array_diff($index->getFulltextFields(), SearchSources::PRIVATE_FIELDS)));
    $search->sort('search_api_relevance', QueryInterface::SORT_DESC);
    // Results that tie otherwise come back in a different order each time, so
    // one page repeats rows from another and some are never shown.
    $search->sort('search_api_id', QueryInterface::SORT_ASC);

    $sources = $search->createConditionGroup('OR');
    foreach ($source_keys as $key) {
      $definition = SearchSources::get($key);
      if (($definition['kind'] ?? '') === 'local') {
        $sources->addConditionGroup($this->sourceConditions($search, $definition, $refines[$key] ?? []));
      }
    }
    $search->addConditionGroup($sources);
    return $search;
  }

  /**
   * Archive records of these types with no PDF link, as Search API item IDs.
   *
   * Asked of the database, so it holds whether or not the link is in the
   * index, and a record drops out as soon as it's saved without one.
   */
  private function withoutUrl(array $types): array {
    $key = implode(',', $types);
    if (!isset($this->withoutUrl[$key])) {
      $rows = $this->entityTypeManager->getStorage('node')->getAggregateQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'archive_record')
        ->condition('field_record_type', $types, 'IN')
        ->notExists('field_record_url')
        ->groupBy('nid')
        ->groupBy('langcode')
        ->execute();
      $this->withoutUrl[$key] = array_map(static fn(array $row): string => 'entity:node/' . $row['nid'] . ':' . $row['langcode'], $rows);
    }
    return $this->withoutUrl[$key];
  }

  /**
   * One source's filters.
   */
  private function sourceConditions(QueryInterface $search, array $definition, array $refine): ConditionGroupInterface {
    $group = $search->createConditionGroup('AND');

    if ($refine && isset($definition['refine_field'])) {
      $group->addCondition($definition['refine_field'], array_map('strval', $refine), 'IN');
    }
    if (isset($definition['record_types'])) {
      $group->addCondition($definition['refine_field'], $definition['record_types'], 'IN');
    }
    if (!empty($definition['needs_url']) && ($missing = $this->withoutUrl($definition['needs_url']))) {
      $group->addCondition('search_api_id', $missing, 'NOT IN');
    }

    if (isset($definition['not_categories'])) {
      // Only Tiles have a category, so the exclusion can't apply to other
      // types: their empty field must not drop them from the results.
      $types = $search->createConditionGroup('OR');
      foreach ($definition['types'] as $type) {
        if ($type === 'tile') {
          $tile = $search->createConditionGroup('AND');
          $tile->addCondition('type', 'tile');
          $tile->addCondition('field_category', $definition['not_categories'], 'NOT IN');
          $types->addConditionGroup($tile);
        }
        else {
          $types->addCondition('type', $type);
        }
      }
      $group->addConditionGroup($types);
    }
    else {
      $group->addCondition('type', $definition['types'], 'IN');
    }

    if (isset($definition['categories'])) {
      $group->addCondition('field_category', $definition['categories'], 'IN');
    }
    if (isset($definition['article_categories'])) {
      $group->addCondition('field_article_category', $definition['article_categories'], 'IN');
    }
    return $group;
  }

  /**
   * One result row from its node.
   *
   * @param string|null $excerpt
   *   The index's excerpt for this result, when its Highlight processor makes
   *   one. Only an archive record shows it: there it's the words around the
   *   match in the record's PDF, which is most of what's searched. It's shown
   *   only if it holds a searched word.
   */
  private function row(NodeInterface $node, array $source_keys, array $words, ?string $excerpt = NULL): array {
    [$title, $summary, $meta, $url] = match ($node->bundle()) {
      'tile' => [
        $this->text($node, 'field_title') ?: $node->label(),
        $this->tileSummary($node, $source_keys),
        '',
        $this->linkField($node, ['field_tile_link']),
      ],
      'archive_record' => [
        $node->label(),
        $this->text($node, 'field_summary') ?: $this->text($node, 'field_description'),
        SearchSources::get('archive')['record_labels'][$this->text($node, 'field_record_type')] ?? '',
        $this->linkField($node, ['field_record_url']),
      ],
      'article' => [
        $node->label(),
        $this->bodySummary($node),
        $this->articleDate($node),
        NULL,
      ],
      'e_books' => [
        $this->text($node, 'field_book_title') ?: $node->label(),
        $this->text($node, 'field_course_title'),
        implode(' · ', array_filter([$this->text($node, 'field_book_author'), $this->text($node, 'field_course_code'), $this->term($node)])),
        $this->linkField($node, ['field_book_url']),
      ],
      'staff' => [
        trim($this->text($node, 'field_first_name') . ' ' . $this->text($node, 'field_last_name')) ?: $node->label(),
        '',
        $this->text($node, 'field_title'),
        NULL,
      ],
      default => [
        $node->label(),
        $this->bodySummary($node),
        '',
        NULL,
      ],
    };

    $url = ResultText::url($url ?: $node->toUrl()->toString());
    return [
      'title' => ResultText::highlight(ResultText::clean($title), $words),
      'url' => $url,
      'external' => (bool) preg_match('#^https?://(?!lib\.lsu\.edu)#i', $url),
      'summary' => ResultText::highlight(!empty($this->sourceOf($node, $source_keys)['whole_summary']) ? ResultText::paragraphs($summary) : ResultText::summary($summary), $words),
      'excerpt' => $node->bundle() === 'archive_record' ? $this->excerpt($excerpt, $words) : '',
      'pdf' => $node->bundle() === 'archive_record' ? $this->pdfFacts->forUrl($url) : [],
      // A finding aid's or abstract's PDF, which finds its Catalog record.
      'archive_pdf' => $node->bundle() === 'archive_record' ? ResultText::archivePdf($url) : NULL,
      'meta' => ResultText::clean($meta),
      'source' => count($source_keys) > 1 ? ($this->sourceOf($node, $source_keys)['label'] ?? NULL) : NULL,
      'image' => !empty($this->sourceOf($node, $source_keys)['images']) ? $this->image($node) : NULL,
      'image_url' => $node->bundle() === 'e_books' ? ResultText::imageUrl($this->text($node, 'field_book_image_url')) : NULL,
      'details' => $node->bundle() === 'e_books' ? [$this->bookDetails($node)] : NULL,
    ];
  }

  /**
   * An excerpt as plain text, marked as this module marks words, or ''.
   *
   * The processor's own markup is dropped, so the page marks words one way
   * everywhere, and an excerpt with no searched word in it isn't shown.
   */
  private function excerpt(?string $excerpt, array $words): MarkupInterface|string {
    $text = ResultText::clean($excerpt);
    if ($text === '' || !$words) {
      return '';
    }
    // Text from a PDF can leave a space before punctuation: "fever ."
    $text = preg_replace('/\s+([.,;:!?)])/u', '$1', $text) ?? $text;
    // The processor stops at its length, often mid-word: end at a word instead.
    if (!str_ends_with($text, '…')) {
      $space = mb_strrpos($text, ' ');
      $text = rtrim($space ? mb_substr($text, 0, $space) : $text, ' ,;:.') . ' …';
    }
    $marked = ResultText::highlight($text, $words);
    return str_contains((string) $marked, '<mark>') ? $marked : '';
  }

  /**
   * A tile's summary: the first of its source's fields with real content.
   */
  private function tileSummary(NodeInterface $node, array $source_keys): string {
    $fields = $this->sourceOf($node, $source_keys)['summary_fields']
      ?? ['field_tile_long_summary', 'field_tile_text', 'field_tile_linked_text'];
    foreach ($fields as $field) {
      $text = $this->text($node, $field);
      if (ResultText::clean($text) !== '' && !ResultText::isPlaceholder(ResultText::clean($text))) {
        return $text;
      }
    }
    return '';
  }

  /**
   * One copy's course details, for the row's Book details.
   */
  private function bookDetails(NodeInterface $node): array {
    $instructor = ResultText::clean($this->text($node, 'field_instructor'));
    return [
      'class' => ResultText::clean(implode(': ', array_filter([$this->text($node, 'field_course_code'), $this->text($node, 'field_course_title')]))),
      'instructor' => $instructor,
      'instructor_is_email' => (bool) filter_var($instructor, FILTER_VALIDATE_EMAIL),
      'section' => ResultText::clean($this->text($node, 'field_section')),
      'term' => $this->term($node),
    ];
  }

  /**
   * An E-Textbook's term as people say it: LSUAM_FALL_2026 is Fall 2026, and
   * LSUAM_ONLINE_FALL_2_2026 is Fall 2 2026 (Online).
   */
  private function term(NodeInterface $node): string {
    $term = $this->text($node, 'field_term');
    if (!preg_match('/_(ONLINE_)?(FALL|SPRING|SUMMER|WINTER)(?:_?(\d))?_(\d{4})$/', $term, $match)) {
      return ResultText::clean($term);
    }
    return ucfirst(strtolower($match[2])) . ($match[3] !== '' ? ' ' . $match[3] : '') . ' ' . $match[4] . ($match[1] !== '' ? ' (Online)' : '');
  }

  /**
   * A node's picture as a small lazy image, or NULL.
   *
   * alt is empty because the title beside it already names the result.
   */
  private function image(NodeInterface $node): ?array {
    $bundle = $node->bundle();
    foreach (self::IMAGE_FIELDS[$bundle] ?? [] as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
        continue;
      }
      $item = $this->imageItem($node->get($field)->first());
      $file = $item?->entity;
      $style = $file ? $this->imageStyle(self::IMAGE_STYLES[$bundle] ?? self::IMAGE_STYLES['default']) : NULL;
      if ($style) {
        return [
          '#theme' => 'image_style',
          '#style_name' => $style,
          '#uri' => $file->getFileUri(),
          '#width' => $item->width,
          '#height' => $item->height,
          '#alt' => '',
          '#attributes' => ['loading' => 'lazy', 'decoding' => 'async'],
        ];
      }
    }
    return NULL;
  }

  /**
   * The image item in a field: itself, or the one inside a Media reference.
   */
  private function imageItem(FieldItemInterface $item): ?FieldItemInterface {
    $target = $item->entity;
    if (!$target instanceof MediaInterface) {
      return $item;
    }
    $source = $target->getSource()->getConfiguration()['source_field'] ?? NULL;
    return $source && $target->hasField($source) && !$target->get($source)->isEmpty()
      ? $target->get($source)->first()
      : NULL;
  }

  /**
   * The first of these image styles that exists, or NULL.
   */
  private function imageStyle(array $names): ?string {
    $storage = $this->entityTypeManager->getStorage('image_style');
    foreach ($names as $name) {
      if ($storage->load($name)) {
        return $name;
      }
    }
    return NULL;
  }

  /**
   * Which of the listed sources a node belongs to, or NULL.
   */
  private function sourceOf(NodeInterface $node, array $source_keys): ?array {
    $category = $node->hasField('field_category') ? (int) $node->get('field_category')->target_id : NULL;
    foreach ($source_keys as $key) {
      $definition = SearchSources::get($key);
      if (!in_array($node->bundle(), $definition['types'] ?? [], TRUE)) {
        continue;
      }
      if (isset($definition['categories']) && !in_array($category, $definition['categories'], TRUE)) {
        continue;
      }
      if (isset($definition['not_categories']) && in_array($category, $definition['not_categories'], TRUE)) {
        continue;
      }
      return $definition;
    }
    return NULL;
  }

  /**
   * A field's first value as text, or ''.
   */
  private function text(NodeInterface $node, string $field): string {
    return $node->hasField($field) && !$node->get($field)->isEmpty()
      ? (string) ($node->get($field)->first()->getValue()['value'] ?? '')
      : '';
  }

  /**
   * The first filled link: the named fields, then any link field on the node.
   *
   * Result links usually point outside the node (a Tile's destination, a
   * database). Fields hold either a link item or plain text (an E-Textbook's
   * field_book_url is plain text), so both are read.
   */
  private function linkField(NodeInterface $node, array $fields): ?string {
    foreach ($fields as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
        continue;
      }
      $item = $node->get($field)->first();
      if ($item->getFieldDefinition()->getType() === 'link') {
        return $item->getUrl()->toString();
      }
      $value = trim((string) ($item->getValue()['value'] ?? ''));
      if (preg_match('#^(https?://|/)#i', $value)) {
        return $value;
      }
    }
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'link' && !$node->get($name)->isEmpty()) {
        return $node->get($name)->first()->getUrl()->toString();
      }
    }
    return NULL;
  }

  /**
   * The body's summary, or the body.
   */
  private function bodySummary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $body = $node->get('body')->first()->getValue();
    return (string) (($body['summary'] ?? '') ?: ($body['value'] ?? ''));
  }

  /**
   * An article's date, as the site writes dates.
   */
  private function articleDate(NodeInterface $node): string {
    $value = $this->text($node, 'field_article_date');
    $time = $value !== '' ? strtotime($value) : FALSE;
    return $time ? $this->dateFormatter->format($time, 'custom', 'F j, Y') : '';
  }

}

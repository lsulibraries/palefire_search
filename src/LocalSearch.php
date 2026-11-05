<?php

namespace Drupal\palefire_search;

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
   * Counts this request already knows, so a section and its filter share one.
   */
  private array $counts = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * One page of results across these sources.
   *
   * @return array
   *   total, and items: [title, url, external, summary, meta, source, image,
   *   image_url, details].
   */
  public function search(array $source_keys, string $query, array $refines, int $page, int $limit, string $sort = 'relevance'): array {
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
        $items[] = $this->row($this->entityRepository->getTranslationFromContext($node), $source_keys, $words);
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
    $search = $this->build($source_keys, $query, $refines);
    if (!$search) {
      return 0;
    }
    $search->range(0, 0);
    return $this->counts[$key] = (int) $search->execute()->getResultCount();
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
      $sorts = [$field => $sort === 'oldest' ? QueryInterface::SORT_ASC : QueryInterface::SORT_DESC];
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
   * One source's filters.
   */
  private function sourceConditions(QueryInterface $search, array $definition, array $refine): ConditionGroupInterface {
    $group = $search->createConditionGroup('AND');

    if ($refine && isset($definition['refine_field'])) {
      $group->addCondition($definition['refine_field'], array_map('strval', $refine), 'IN');
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
   */
  private function row(NodeInterface $node, array $source_keys, array $words): array {
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
        SearchSources::get('archive')['refine_options'][$this->text($node, 'field_record_type')] ?? '',
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
      'summary' => ResultText::highlight(ResultText::summary($summary), $words),
      'meta' => ResultText::clean($meta),
      'source' => count($source_keys) > 1 ? ($this->sourceOf($node, $source_keys)['label'] ?? NULL) : NULL,
      'image' => !empty($this->sourceOf($node, $source_keys)['images']) ? $this->image($node) : NULL,
      'image_url' => $node->bundle() === 'e_books' ? ResultText::imageUrl($this->text($node, 'field_book_image_url')) : NULL,
      'details' => $node->bundle() === 'e_books' ? $this->bookDetails($node) : NULL,
    ];
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
   * An E-Textbook's course details, for the row's Book details.
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
   * An E-Textbook's term as people say it: LSUAM_FALL_2026 is Fall 2026.
   */
  private function term(NodeInterface $node): string {
    $term = $this->text($node, 'field_term');
    return preg_match('/_(FALL|SPRING|SUMMER|WINTER)[A-Z0-9]*_(\d{4})$/', $term, $match)
      ? ucfirst(strtolower($match[1])) . ' ' . $match[2]
      : ResultText::clean($term);
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

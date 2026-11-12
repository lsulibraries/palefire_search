<?php

namespace Drupal\palefire_search;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Searches the Scholarly Repository's records that RepositoryHarvest keeps.
 *
 * Titles, authors, abstracts and keywords are searched, all words required,
 * titles counting most. The text of the files isn't here: the source's note
 * and its link send people to the repository's own search for that.
 *
 * On MySQL it uses the table's full-text indexes, which skip words under three
 * letters and a few very common ones; those are left out of the search rather
 * than finding nothing. On another database each word is matched with LIKE.
 */
class RepositorySearch {

  use StringTranslationTrait;

  /**
   * MySQL's (InnoDB's) default stopwords: not indexed, so never required.
   */
  private const STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

  /**
   * Shortest word the full-text index holds.
   */
  private const MIN_FULLTEXT = 3;

  /**
   * Longest excerpt of an abstract, in characters (ResultText::summary()
   * shortens anything longer), and how much comes before the first match.
   */
  private const EXCERPT = 240;
  private const LEAD = 70;

  /**
   * Authors named on a row before "and others".
   */
  private const AUTHORS = 3;

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * One page of results.
   *
   * @param string $terms
   *   The search, words and phrases only.
   * @param array $filters
   *   from, to: years.
   * @param int $page
   *   The page, from 0.
   * @param int $limit
   *   Results per page.
   * @param string $sort
   *   relevance, newest or oldest.
   *
   * @return array
   *   total, failed (nothing harvested yet), items: [title, url, summary,
   *   meta].
   */
  public function search(string $terms, array $filters, int $page, int $limit, string $sort = 'relevance'): array {
    if (!$this->database->schema()->tableExists(RepositoryHarvest::TABLE) || !$this->database->select(RepositoryHarvest::TABLE)->range(0, 1)->countQuery()->execute()->fetchField()) {
      return ['total' => NULL, 'failed' => TRUE, 'items' => []];
    }
    $words = array_values(array_unique(array_map('mb_strtolower', SearchSources::indexableWordsIn($terms))));
    if (!$words) {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }

    $select = $this->database->select(RepositoryHarvest::TABLE, 'r')
      ->fields('r', ['url', 'title', 'creators', 'abstract', 'subjects', 'kind', 'publication', 'year']);
    $ranked = $this->match($select, $words);
    if (isset($filters['from'])) {
      $select->condition('r.year', $filters['from'], '>=');
    }
    if (isset($filters['to'])) {
      $select->condition('r.year', $filters['to'], '<=');
    }

    $total = (int) $select->countQuery()->execute()->fetchField();
    match (TRUE) {
      $sort === 'newest' => $select->orderBy('r.date', 'DESC'),
      // Records with no date go last, not first.
      $sort === 'oldest' => $select->orderBy($select->addExpression("r.date = ''", 'undated'))->orderBy('r.date', 'ASC'),
      $ranked => $select->orderBy('score', 'DESC'),
      default => $select->orderBy('r.date', 'DESC'),
    };
    $select->orderBy('r.oai_id');
    $select->range($page * $limit, $limit);

    $items = [];
    foreach ($select->execute() as $row) {
      $items[] = $this->item($row, $words);
    }
    return ['total' => $total, 'failed' => FALSE, 'items' => $items];
  }

  /**
   * Adds the words to the query. TRUE when a relevance score is available.
   */
  private function match(SelectInterface $select, array $words): bool {
    $fulltext = $this->database->databaseType() === 'mysql'
      ? array_values(array_filter($words, static fn(string $word): bool => mb_strlen($word) >= self::MIN_FULLTEXT && !in_array($word, self::STOPWORDS, TRUE)))
      : [];
    // Words the index skips still have to be somewhere, so they're matched
    // with LIKE, which is slow but only narrows what the index found.
    $rest = array_diff($words, $fulltext);

    if ($fulltext) {
      // Only letters and numbers reach here, so nothing in a word is an operator.
      $against = implode(' ', array_map(static fn(string $word): string => '+' . $word, $fulltext));
      $select->where('MATCH (r.title, r.creators, r.abstract, r.subjects) AGAINST (:pf_where IN BOOLEAN MODE)', [':pf_where' => $against]);
      $select->addExpression('MATCH (r.title) AGAINST (:pf_title IN BOOLEAN MODE) * 3 + MATCH (r.title, r.creators, r.abstract, r.subjects) AGAINST (:pf_all IN BOOLEAN MODE)', 'score', [
        ':pf_title' => $against,
        ':pf_all' => $against,
      ]);
    }
    foreach ($rest as $word) {
      $like = '%' . $this->database->escapeLike($word) . '%';
      $select->condition($select->orConditionGroup()
        ->condition('r.title', $like, 'LIKE')
        ->condition('r.creators', $like, 'LIKE')
        ->condition('r.abstract', $like, 'LIKE')
        ->condition('r.subjects', $like, 'LIKE'));
    }
    return (bool) $fulltext;
  }

  /**
   * A record as a result row.
   */
  private function item(object $row, array $words): array {
    $creators = array_filter(explode("\n", (string) $row->creators));
    $authors = implode('; ', array_slice($creators, 0, self::AUTHORS)) . (count($creators) > self::AUTHORS ? ' ' . $this->t('and others') : '');
    $abstract = (string) $row->abstract;
    return [
      'title' => (string) $row->title,
      'url' => ResultText::url((string) $row->url),
      // The abstract around the first word it has, or its keywords when it
      // has no abstract.
      'summary' => $abstract !== '' ? self::excerpt($abstract, $words) : implode(', ', array_filter(explode("\n", (string) $row->subjects))),
      'meta' => implode(' · ', array_filter([$authors, $row->kind, $row->publication, $row->year])),
    ];
  }

  /**
   * The part of a text around the first of the words, cut at spaces, with an
   * ellipsis where it was cut. At most EXCERPT characters.
   */
  public static function excerpt(string $text, array $words): string {
    if (mb_strlen($text) <= self::EXCERPT) {
      return $text;
    }
    $first = NULL;
    foreach ($words as $word) {
      if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/iu', $text, $match, PREG_OFFSET_CAPTURE)) {
        $at = mb_strlen(substr($text, 0, $match[0][1]));
        $first = $first === NULL ? $at : min($first, $at);
      }
    }
    $start = $first === NULL || $first <= self::LEAD ? 0 : $first - self::LEAD;
    if ($start > 0) {
      // Begin at a word, and no later than needed to fill the excerpt.
      $start = min($start, mb_strlen($text) - self::EXCERPT + 1);
      $space = mb_strpos($text, ' ', $start);
      $start = $space === FALSE ? $start : $space + 1;
    }
    $length = self::EXCERPT - ($start > 0 ? 1 : 0) - 1;
    $part = mb_substr($text, $start, $length);
    $cut = $start + $length < mb_strlen($text);
    if ($cut) {
      $space = mb_strrpos($part, ' ');
      $part = rtrim($space ? mb_substr($part, 0, $space) : $part, ' ,;:.');
    }
    return ($start > 0 ? '…' : '') . $part . ($cut ? '…' : '');
  }

}

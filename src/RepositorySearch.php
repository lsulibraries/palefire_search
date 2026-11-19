<?php

namespace Drupal\palefire_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Searches the Scholarly Repository.
 *
 * The repository's own search answers this server (its firewall lets it
 * through), and it reads the full text of each document. It decides what
 * matches and in what order; the records RepositoryHarvest keeps add each
 * row's abstract and kind, which its answer leaves out. It answers 25 at a
 * time from a given start, so a page may take two answers, each kept for ten
 * minutes. It sorts by relevance or newest only.
 *
 * When it can't be read, or for oldest first, the kept records are searched
 * instead: titles, authors, abstracts and keywords, all words required, titles
 * counting most. On MySQL that uses the table's full-text indexes, which skip
 * words under three letters and a few very common ones; those are left out of
 * the search rather than finding nothing. On another database each word is
 * matched with LIKE.
 */
class RepositorySearch {

  use StringTranslationTrait;

  /**
   * The search's address, the whole repository's context, and how many
   * results each answer holds.
   */
  private const SEARCH = 'https://repository.lsu.edu/do/search/results/json';
  private const CONTEXT = 8403704;
  private const BLOCK = 25;

  /**
   * How long an answer is kept, in seconds, and how long to wait for one.
   */
  private const KEEP = 600;
  private const TIMEOUT = 8;

  /**
   * Its answer's download formats that are the document itself, online.
   */
  private const ONLINE = ['pdf', 'native', 'picture', 'external'];

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
    private readonly ClientInterface $client,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CacheBackendInterface $cache,
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
   *   total, failed, items: [title, url, summary, meta, full_text_url]. backup
   *   and backup_note when the kept records answered for the repository.
   */
  public function search(string $terms, array $filters, int $page, int $limit, string $sort = 'relevance'): array {
    $words = array_values(array_unique(array_map('mb_strtolower', SearchSources::indexableWordsIn($terms))));
    if (!$words) {
      return ['total' => 0, 'failed' => FALSE, 'items' => []];
    }
    $limit = max(1, $limit);
    if ($sort !== 'oldest') {
      $results = $this->live($terms, $filters, $page, $limit, $sort, $words);
      if ($results !== NULL) {
        return $results;
      }
      $kept = $this->kept($terms, $filters, $page, $limit, $sort);
      return $kept['failed'] ? $kept : $kept + [
        'backup' => TRUE,
        'backup_note' => 'The Scholarly Repository’s search isn’t answering, so these come from a copy of its records, which knows their titles, authors, abstracts and keywords but not their full text.',
      ];
    }
    return $this->kept($terms, $filters, $page, $limit, $sort);
  }

  /**
   * A page from the repository's own search, or NULL if it couldn't be read.
   */
  private function live(string $terms, array $filters, int $page, int $limit, string $sort, array $words): ?array {
    $first = $page * $limit;
    $from = intdiv($first, self::BLOCK) * self::BLOCK;
    $docs = [];
    $total = 0;
    for ($start = $from; $start < $first + $limit; $start += self::BLOCK) {
      $block = $this->block($terms, $filters, $start, $sort);
      if ($block === NULL) {
        return NULL;
      }
      $total = $block['total'];
      array_push($docs, ...$block['docs']);
      if (count($block['docs']) < self::BLOCK) {
        break;
      }
    }
    $docs = array_slice($docs, $first - $from, $limit);

    $kept = $this->keptRows(array_map(static fn(array $doc): string => self::https((string) ($doc['url'] ?? '')), $docs));
    $items = [];
    foreach ($docs as $doc) {
      $item = $this->liveItem($doc, $kept[self::https((string) ($doc['url'] ?? ''))] ?? NULL, $words);
      if ($item) {
        $items[] = $item;
      }
    }
    return ['total' => $total, 'failed' => FALSE, 'items' => $items];
  }

  /**
   * One answer, kept for KEEP seconds: total and docs. NULL on a failure.
   */
  private function block(string $terms, array $filters, int $start, string $sort): ?array {
    $query = [
      'q' => $terms,
      'start' => $start,
      'context' => self::CONTEXT,
      'format' => 'json',
    ];
    if ($sort === 'newest') {
      $query['sort'] = 'date_desc';
    }
    // It reads dates as month/day/year.
    if (isset($filters['from'])) {
      $query['start_date'] = '01/01/' . (int) $filters['from'];
    }
    if (isset($filters['to'])) {
      $query['end_date'] = '12/31/' . (int) $filters['to'];
    }
    $key = 'palefire_search:repository:' . hash('sha256', serialize($query));
    if ($kept = $this->cache->get($key)) {
      return $kept->data;
    }

    try {
      $response = $this->client->request('GET', self::SEARCH, [
        'query' => $query,
        'connect_timeout' => 5,
        'timeout' => self::TIMEOUT,
        'http_errors' => FALSE,
        'headers' => ['User-Agent' => 'LSU Libraries search', 'Accept' => 'application/json'],
      ]);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('palefire_search')->error('Scholarly Repository search failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    $answer = $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), TRUE) : NULL;
    if (!is_array($answer) || !isset($answer['num_found']) || !is_array($answer['docs'] ?? NULL)) {
      // A 403 here is its firewall's challenge: this server is no longer let
      // through.
      $this->loggerFactory->get('palefire_search')->error('Scholarly Repository search gave no usable answer (HTTP @code).', ['@code' => $response->getStatusCode()]);
      return NULL;
    }
    $block = [
      'total' => (int) $answer['num_found'],
      'docs' => array_values(array_filter($answer['docs'], 'is_array')),
    ];
    $this->cache->set($key, $block, time() + self::KEEP);
    return $block;
  }

  /**
   * The kept records at these addresses, by address.
   */
  private function keptRows(array $urls): array {
    $urls = array_values(array_filter(array_unique($urls)));
    if (!$urls || !$this->database->schema()->tableExists(RepositoryHarvest::TABLE)) {
      return [];
    }
    $rows = [];
    $select = $this->database->select(RepositoryHarvest::TABLE, 'r')
      ->fields('r', ['url', 'abstract', 'subjects', 'kind', 'publication', 'year'])
      ->condition('r.url', $urls, 'IN');
    foreach ($select->execute() as $row) {
      $rows[self::https((string) $row->url)] = $row;
    }
    return $rows;
  }

  /**
   * A result from the repository's search as a row, with what the kept
   * record adds. NULL without a title or an address in the repository.
   */
  private function liveItem(array $doc, ?object $kept, array $words): ?array {
    $title = trim((string) ($doc['title'] ?? ''));
    $url = self::https((string) ($doc['url'] ?? ''));
    if ($title === '' || !str_starts_with($url, 'https://repository.lsu.edu/')) {
      return NULL;
    }
    $creators = array_values(array_filter(array_map('strval', (array) ($doc['author_display'] ?? $doc['author'] ?? []))));
    $type = (array) ($doc['document_type'] ?? []);
    $year = preg_match('/(\d{4})$/', (string) ($doc['publication_date'] ?? ''), $match) ? $match[1] : ($kept->year ?? '');
    $download = (string) ($doc['download_link'] ?? '');
    $online = in_array($doc['download_format'] ?? '', self::ONLINE, TRUE) && preg_match('#^https?://#i', $download);
    return [
      'title' => $title,
      'url' => $url,
      'summary' => $kept ? $this->summary($kept, $words) : '',
      'meta' => implode(' · ', array_filter([
        $this->authors($creators),
        ($kept->kind ?? '') ?: (string) ($type[1] ?? ''),
        (string) ($doc['publication_title'] ?? '') ?: ($kept->publication ?? ''),
        $year,
      ])),
      'full_text_url' => $online ? ResultText::url($download) : NULL,
    ];
  }

  /**
   * An address in the repository as https, as the kept records have them;
   * its search gives them as http.
   */
  private static function https(string $url): string {
    return preg_replace('#^http://repository\.lsu\.edu/#i', 'https://repository.lsu.edu/', trim($url)) ?? '';
  }

  /**
   * The first authors, then "and others".
   */
  private function authors(array $creators): string {
    return implode('; ', array_slice($creators, 0, self::AUTHORS)) . (count($creators) > self::AUTHORS ? ' ' . $this->t('and others') : '');
  }

  /**
   * The abstract around the first word it has, or its keywords when it has no
   * abstract.
   */
  private function summary(object $row, array $words): string {
    $abstract = (string) $row->abstract;
    return $abstract !== '' ? self::excerpt($abstract, $words) : implode(', ', array_filter(explode("\n", (string) $row->subjects)));
  }

  /**
   * A page from the kept records.
   */
  private function kept(string $terms, array $filters, int $page, int $limit, string $sort): array {
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
    return [
      'title' => (string) $row->title,
      'url' => ResultText::url((string) $row->url),
      'summary' => $this->summary($row, $words),
      'meta' => implode(' · ', array_filter([$this->authors(array_values(array_filter(explode("\n", (string) $row->creators)))), $row->kind, $row->publication, $row->year])),
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

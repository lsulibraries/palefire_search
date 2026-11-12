<?php

namespace Drupal\palefire_search;

/**
 * Reads AND, OR, NOT, -word, "phrases", (groups), word endings and fields.
 *
 * One reading for every source, written out in the form each one understands.
 * Operators count in capitals only, so "not" and "or" stay ordinary words, and
 * a search of just AND or NOT is a search for that word. NOT and AND bind
 * tighter than OR: a AND b OR c is (a AND b) OR c.
 *
 * A word ending in * finds the words that start with it (econom* finds
 * economics and economy), from three letters, so a* doesn't ask for half the
 * index. author:, title: and subject: count only with their value right after
 * the colon (title:hamlet, title:"light in august"), so a pasted "Title: The
 * Hamlet" stays words. year: is read apart, by years(), as the Year published
 * filter.
 */
final class SearchTerms {

  /**
   * The fields a search can name, as typed before the colon.
   */
  public const FIELDS = ['author', 'title', 'subject'];

  /**
   * The fewest letters before a * that make it a word ending.
   */
  public const PREFIX_MIN = 3;

  /**
   * Discovery's field codes.
   */
  private const DISCOVERY_FIELDS = ['author' => 'AU', 'title' => 'TI', 'subject' => 'SU'];

  /**
   * The Catalog's field names, as its advanced search writes them.
   */
  private const CATALOG_FIELDS = ['author' => 'AUTHOR', 'title' => 'TITLE', 'subject' => 'SUBJECT'];

  /**
   * Whether the search uses an operator. Parentheses alone don't count.
   */
  public static function hasOperators(string $query): bool {
    foreach (self::tokens($query) as $token) {
      if ($token['negated'] || $token['operator']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the search names a field, such as author:.
   */
  public static function hasFields(string $query): bool {
    foreach (self::tokens($query) as $token) {
      if ($token['field'] !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the search has a word ending, such as econom*.
   */
  public static function hasPrefixes(string $query): bool {
    foreach (self::tokens($query) as $token) {
      if ($token['prefix']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A year: in the search, and the search without it, or NULL.
   *
   * Reads year:1862, year:1860-1865, year:1860- and year:-1865.
   *
   * @return array|null
   *   query: the search without it; from, to: years, NULL when open.
   */
  public static function years(string $query): ?array {
    $pattern = '/(?<![^\s(])year:(?<from>[12]\d{3})?(?:(?<dash>[-–])(?<to>[12]\d{3})?)?(?=$|[\s)])/iu';
    if (!preg_match($pattern, $query, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
      return NULL;
    }
    $from = isset($match['from'][0]) ? (int) $match['from'][0] : NULL;
    $to = isset($match['to'][0]) ? (int) $match['to'][0] : NULL;
    if ($from === NULL && $to === NULL) {
      return NULL;
    }
    // A single year is that year alone.
    if (!isset($match['dash'][0])) {
      $to = $from;
    }
    $rest = substr_replace($query, ' ', $match[0][1], strlen($match[0][0]));
    return [
      'query' => trim(preg_replace('/\s+/u', ' ', $rest) ?? ''),
      'from' => $from,
      'to' => $to,
    ];
  }

  /**
   * The words a result should have: what to mark, without any excluded ones.
   *
   * @return string[]
   *   The words and phrases, in order, without duplicates. A word ending is
   *   its first letters, without the *.
   */
  public static function wanted(string $query): array {
    return array_values(array_unique(array_column(self::wantedTerms(self::tree($query)), 'word')));
  }

  /**
   * The search for the site's own index, or NULL if nothing in it can be
   * searched.
   *
   * Words the index can't match are dropped, as is any branch that only
   * excludes: on its own it would match nearly everything in the index. A
   * field term that the whole search needs (not under OR or in a group) is
   * read as a condition on that field's index fields; elsewhere it's a word.
   *
   * @param string $query
   *   The search as typed.
   * @param callable $expand
   *   Given a word's first letters, the index's words that start with them.
   *
   * @return array|null
   *   keys: Search API keys, or NULL when only fields are searched; fields:
   *   [field, words, not] for each field term, words being several when it's
   *   a word ending.
   */
  public static function forIndex(string $query, callable $expand): ?array {
    $tree = self::tree($query);
    $fields = [];
    foreach ($tree['items'] as $or => $branch) {
      if (count($branch['items']) !== 1) {
        continue;
      }
      foreach ($branch['items'][0]['items'] as $index => $item) {
        if (isset($item['word']) && $item['field'] !== NULL && SearchSources::indexableWordsIn($item['word'])) {
          $words = $item['prefix'] ? array_values($expand($item['word'])) : [];
          $fields[] = ['field' => $item['field'], 'words' => $words ?: [$item['word']], 'not' => $item['not']];
          unset($tree['items'][$or]['items'][0]['items'][$index]);
        }
      }
    }

    $wanted_field = (bool) array_filter($fields, static fn(array $field): bool => !$field['not']);
    // With a field to look for, a search that otherwise only excludes still
    // says what to look for.
    $keys = self::keys($tree, $expand, $wanted_field ? 0 : NULL);
    if (!$wanted_field && ($keys === NULL || !self::isWanted($keys))) {
      return NULL;
    }
    if (is_string($keys)) {
      $keys = ['#conjunction' => 'AND', $keys];
    }
    return ['keys' => $keys, 'fields' => $fields];
  }

  /**
   * The search as Discovery reads it.
   *
   * A plain search goes as typed, so Discovery's own matching still applies.
   * Otherwise it's written out in full: Discovery has no -word, needs what's
   * wanted before NOT, and parentheses keep the meaning the same as elsewhere.
   * Fields become its codes: author:faulkner is AU faulkner.
   */
  public static function forDiscovery(string $query): string {
    if (!self::hasOperators($query) && !self::hasFields($query)) {
      return $query;
    }
    return self::written(self::tree($query)) ?: $query;
  }

  /**
   * The search as the Catalog reads it: words, phrases and word endings.
   *
   * Its feed reads AND and NOT as words, so they're left out with excluded
   * words. It reads one field to a search: a search of a single field term
   * goes as TITLE="light in august", and any other search with fields is
   * searched as its words. The page says so (lostBy()).
   */
  public static function forCatalog(string $query): string {
    if (!self::hasOperators($query) && !self::hasFields($query)) {
      // An = would end the search in the feed's address.
      return trim(str_replace('=', ' ', $query));
    }
    $terms = self::wantedTerms(self::tree($query));
    if (!self::hasOperators($query) && count($terms) === 1 && $terms[0]['field'] !== NULL) {
      return self::CATALOG_FIELDS[$terms[0]['field']] . '=' . self::termText($terms[0]);
    }
    return implode(' ', array_map(self::termText(...), $terms));
  }

  /**
   * The search as Research Guides reads it: words only.
   *
   * LibGuides reads operators as words to find, so they're left out, along with
   * excluded words, fields and the * of a word ending. Results can't honor
   * them, which the page says.
   *
   * @return string
   *   The words, or '' if the search only excludes.
   */
  public static function forGuides(string $query): string {
    if (!self::hasOperators($query) && !self::hasFields($query) && !self::hasPrefixes($query)) {
      return $query;
    }
    return implode(' ', array_map(self::quoted(...), self::wanted($query)));
  }

  /**
   * Whether a service searched for less than was asked, so the page says
   * what it searched for instead.
   */
  public static function lostBy(string $adapter, string $query): bool {
    return match ($adapter) {
      'catalog' => self::hasOperators($query) || (self::hasFields($query) && !preg_match('/^[A-Z]+=/', self::forCatalog($query))),
      'guides', 'repository' => self::hasOperators($query) || self::hasFields($query) || self::hasPrefixes($query),
      default => FALSE,
    };
  }

  /**
   * Splits a search into words, phrases, field terms, operators and
   * parentheses.
   */
  private static function tokens(string $query): array {
    $pattern = '/(?<fneg>-?)(?<field>(?i:author|title|subject)):(?:"(?<fphrase>[^"]*)"?|(?<fword>[^\s()"]+))|(?<pneg>-?)"(?<phrase>[^"]*)"?|-?\(|\)|[^\s()]+/u';
    preg_match_all($pattern, $query, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    $tokens = [];
    foreach ($matches as $match) {
      $text = $match[0];
      $token = ['text' => $text, 'negated' => FALSE, 'operator' => FALSE, 'open' => FALSE, 'close' => FALSE, 'field' => NULL, 'prefix' => FALSE];
      $phrase = FALSE;
      if (($match['field'] ?? NULL) !== NULL) {
        $token['field'] = strtolower($match['field']);
        $token['negated'] = $match['fneg'] === '-';
        $phrase = ($match['fphrase'] ?? NULL) !== NULL;
        $token['text'] = $phrase ? trim($match['fphrase']) : (string) $match['fword'];
      }
      elseif (($match['phrase'] ?? NULL) !== NULL) {
        $phrase = TRUE;
        $token['text'] = trim($match['phrase']);
        $token['negated'] = $match['pneg'] === '-';
      }
      elseif ($text === '(' || $text === '-(') {
        $token['open'] = TRUE;
        $token['negated'] = $text === '-(';
      }
      elseif ($text === ')') {
        $token['close'] = TRUE;
      }
      elseif (in_array($text, ['AND', 'OR', 'NOT'], TRUE)) {
        $token['operator'] = TRUE;
      }
      elseif ($text[0] === '-') {
        $token['text'] = ltrim($text, '-');
        $token['negated'] = TRUE;
      }

      // A word ending: letters and numbers, then *. Any other * is dropped.
      if (!$phrase && !$token['open'] && !$token['close'] && !$token['operator'] && str_contains($token['text'], '*')) {
        $stem = str_replace('*', '', $token['text']);
        $token['prefix'] = str_ends_with($token['text'], '*') && mb_strlen($stem) >= self::PREFIX_MIN && preg_match('/^[\p{L}\p{N}]+$/u', $stem);
        $token['text'] = $stem;
      }

      if ($token['text'] !== '') {
        $tokens[] = $token;
      }
    }
    // A search of only AND is a search for the word.
    if (count($tokens) === 1) {
      $tokens[0]['operator'] = FALSE;
    }
    return $tokens;
  }

  /**
   * The search as a tree of ['op', 'items', 'not'] and ['word', 'not',
   * 'field', 'prefix'].
   */
  private static function tree(string $query): array {
    $tokens = self::tokens($query);
    $position = 0;
    $tree = ['op' => 'and', 'items' => [], 'not' => FALSE];
    // A stray ")" ends the group early, so carry on with what follows it.
    while ($position < count($tokens)) {
      $tree['items'][] = self::parseOr($tokens, $position);
      if (($tokens[$position]['close'] ?? FALSE)) {
        $position++;
      }
    }
    return $tree;
  }

  /**
   * Reads: and-group OR and-group OR ...
   */
  private static function parseOr(array $tokens, int &$position): array {
    $items = [self::parseAnd($tokens, $position)];
    while (($tokens[$position]['text'] ?? '') === 'OR' && $tokens[$position]['operator']) {
      $position++;
      $items[] = self::parseAnd($tokens, $position);
    }
    return ['op' => 'or', 'items' => $items, 'not' => FALSE];
  }

  /**
   * Reads terms up to an OR or ")": each with AND or nothing between them.
   */
  private static function parseAnd(array $tokens, int &$position): array {
    $items = [];
    $not = FALSE;
    while ($position < count($tokens)) {
      $token = $tokens[$position];
      if ($token['close'] || ($token['operator'] && $token['text'] === 'OR')) {
        break;
      }
      $position++;
      if ($token['operator']) {
        $not = $not || $token['text'] === 'NOT';
        continue;
      }
      $negated = $not || $token['negated'];
      $not = FALSE;
      if ($token['open']) {
        $group = self::parseOr($tokens, $position);
        if ($tokens[$position]['close'] ?? FALSE) {
          $position++;
        }
        $group['not'] = $negated;
        $items[] = $group;
      }
      else {
        $items[] = ['word' => $token['text'], 'not' => $negated, 'field' => $token['field'], 'prefix' => $token['prefix']];
      }
    }
    return ['op' => 'and', 'items' => $items, 'not' => FALSE];
  }

  /**
   * The terms a result should have, as tree nodes, without excluded ones.
   */
  private static function wantedTerms(array $node): array {
    if (!empty($node['not'])) {
      return [];
    }
    if (isset($node['word'])) {
      return [$node];
    }
    return array_merge([], ...array_map(self::wantedTerms(...), $node['items']));
  }

  /**
   * One tree node as Search API keys: a string, an array, or NULL if empty.
   *
   * @param array $node
   *   The node.
   * @param callable|null $expand
   *   Gives the index's words for a word ending.
   * @param int|null $level
   *   0 for the whole search when a field term already says what to look for,
   *   then 1 and 2 for its top-level OR and AND, which may then only exclude.
   *   NULL everywhere else.
   */
  private static function keys(array $node, ?callable $expand = NULL, ?int $level = NULL): string|array|null {
    if (isset($node['word'])) {
      if (!SearchSources::indexableWordsIn($node['word'])) {
        return NULL;
      }
      $keys = $node['word'];
      if ($node['prefix'] && $expand) {
        $words = array_values($expand($node['word']));
        $keys = count($words) > 1 ? ['#conjunction' => 'OR', ...$words] : ($words[0] ?? $node['word']);
      }
      if (!$node['not']) {
        return $keys;
      }
      return is_array($keys) ? ['#negation' => TRUE] + $keys : ['#conjunction' => 'AND', '#negation' => TRUE, $keys];
    }

    $child_level = match (TRUE) {
      $level === 0 => 1,
      $level === 1 && count($node['items']) === 1 => 2,
      default => NULL,
    };
    $items = array_values(array_filter(
      array_map(static fn(array $item): string|array|null => self::keys($item, $expand, $child_level), $node['items']),
      static fn($item): bool => $item !== NULL,
    ));
    if ($node['op'] === 'and' && $level === NULL && !array_filter($items, self::isWanted(...))) {
      // Only exclusions: nothing here says what to look for.
      $items = [];
    }
    if (!$items) {
      return NULL;
    }
    $keys = count($items) === 1 ? $items[0] : ['#conjunction' => strtoupper($node['op'])] + $items;
    if (!empty($node['not'])) {
      $keys = is_array($keys) && !isset($keys['#negation'])
        ? ['#negation' => TRUE] + $keys
        : ['#conjunction' => 'AND', '#negation' => TRUE, $keys];
    }
    return $keys;
  }

  /**
   * Whether rendered keys look for something, rather than leave it out.
   */
  private static function isWanted(string|array $keys): bool {
    return is_string($keys) || empty($keys['#negation']);
  }

  /**
   * One tree node written as Discovery's operators, or '' if empty.
   */
  private static function written(array $node, bool $nested = FALSE): string {
    if (isset($node['word'])) {
      $code = $node['field'] !== NULL ? self::DISCOVERY_FIELDS[$node['field']] . ' ' : '';
      return $code . self::termText($node);
    }
    $wanted = [];
    $excluded = [];
    foreach ($node['items'] as $item) {
      $text = self::written($item, TRUE);
      if ($text !== '') {
        if (!empty($item['not'])) {
          $excluded[] = $text;
        }
        else {
          $wanted[] = $text;
        }
      }
    }
    $text = implode($node['op'] === 'or' ? ' OR ' : ' AND ', $wanted);
    foreach ($excluded as $item) {
      $text .= ($text === '' ? '' : ' ') . 'NOT ' . $item;
    }
    return $nested && count($wanted) + count($excluded) > 1 ? "($text)" : $text;
  }

  /**
   * A term as the outside services read it: a phrase in its quotes, a word
   * ending with its *, and no = (which ends a search in the Catalog's
   * address).
   */
  private static function termText(array $node): string {
    return self::quoted(trim(str_replace('=', ' ', $node['word']))) . ($node['prefix'] ? '*' : '');
  }

  /**
   * A word as typed, or a phrase back in its quotes.
   */
  private static function quoted(string $word): string {
    return preg_match('/\s/u', $word) ? '"' . $word . '"' : $word;
  }

}

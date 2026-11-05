<?php

namespace Drupal\palefire_search;

/**
 * Reads AND, OR, NOT, -word, "phrases" and (groups) in a search.
 *
 * One reading for every source, written out in the form each one understands.
 * Operators count in capitals only, so "not" and "or" stay ordinary words, and
 * a search of just AND or NOT is a search for that word. NOT and AND bind
 * tighter than OR: a AND b OR c is (a AND b) OR c.
 */
final class SearchTerms {

  /**
   * Whether the search uses an operator. Parentheses alone don't count.
   */
  public static function hasOperators(string $query): bool {
    $tokens = self::tokens($query);
    foreach ($tokens as $token) {
      if ($token['negated'] || $token['operator']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The words a result should have: what to mark, without any excluded ones.
   *
   * @return string[]
   *   The words and phrases, in order, without duplicates.
   */
  public static function wanted(string $query): array {
    $wanted = [];
    $collect = static function (array $node) use (&$collect, &$wanted): void {
      if (!empty($node['not'])) {
        return;
      }
      if (isset($node['word'])) {
        $wanted[] = $node['word'];
        return;
      }
      foreach ($node['items'] as $item) {
        $collect($item);
      }
    };
    $collect(self::tree($query));
    return array_values(array_unique($wanted));
  }

  /**
   * The search as Search API keys, or NULL if nothing in it can be searched.
   *
   * Words the index can't match are dropped, as is any branch that only
   * excludes: on its own it would match nearly everything in the index.
   */
  public static function searchKeys(string $query): ?array {
    $keys = self::keys(self::tree($query));
    if ($keys === NULL || !self::isWanted($keys)) {
      return NULL;
    }
    return is_array($keys) ? $keys : ['#conjunction' => 'AND', $keys];
  }

  /**
   * The search as Discovery reads it.
   *
   * A plain search goes as typed, so Discovery's own matching still applies.
   * Otherwise it's written out in full: Discovery has no -word, needs what's
   * wanted before NOT, and parentheses keep the meaning the same as elsewhere.
   */
  public static function forDiscovery(string $query): string {
    if (!self::hasOperators($query)) {
      return $query;
    }
    return self::written(self::tree($query)) ?: $query;
  }

  /**
   * The search as Research Guides reads it: words only.
   *
   * LibGuides reads operators as words to find, so they're left out, along with
   * excluded words. Results can't honor them, which the page says.
   *
   * @return string
   *   The words, or '' if the search only excludes.
   */
  public static function forGuides(string $query): string {
    if (!self::hasOperators($query)) {
      return $query;
    }
    return implode(' ', array_map(self::quoted(...), self::wanted($query)));
  }

  /**
   * Splits a search into words, phrases, operators and parentheses.
   */
  private static function tokens(string $query): array {
    preg_match_all('/(-?)"([^"]*)"?|(-?)\(|\)|[^\s()]+/u', $query, $matches, PREG_SET_ORDER);
    $tokens = [];
    foreach ($matches as $match) {
      $text = $match[0];
      $token = ['text' => $text, 'negated' => FALSE, 'operator' => FALSE, 'open' => FALSE, 'close' => FALSE];
      if ($text[0] === '"' || str_starts_with($text, '-"')) {
        $token['text'] = trim($match[2] ?? '');
        $token['negated'] = $text[0] === '-';
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
        if ($token['text'] === '') {
          continue;
        }
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
   * The search as a tree of ['op', 'items', 'not'] and ['word', 'not'].
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
        $items[] = ['word' => $token['text'], 'not' => $negated];
      }
    }
    return ['op' => 'and', 'items' => $items, 'not' => FALSE];
  }

  /**
   * One tree node as Search API keys: a string, an array, or NULL if empty.
   */
  private static function keys(array $node): string|array|null {
    if (isset($node['word'])) {
      if (!SearchSources::indexableWordsIn($node['word'])) {
        return NULL;
      }
      return $node['not'] ? ['#conjunction' => 'AND', '#negation' => TRUE, $node['word']] : $node['word'];
    }

    $items = array_values(array_filter(array_map(self::keys(...), $node['items']), static fn($item): bool => $item !== NULL));
    if ($node['op'] === 'and' && !array_filter($items, self::isWanted(...))) {
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
      return self::quoted($node['word']);
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
   * A word as typed, or a phrase back in its quotes.
   */
  private static function quoted(string $word): string {
    return preg_match('/\s/u', $word) ? '"' . $word . '"' : $word;
  }

}

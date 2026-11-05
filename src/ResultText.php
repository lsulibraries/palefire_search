<?php

namespace Drupal\palefire_search;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;

/**
 * Turns source text into safe text for a result row.
 */
final class ResultText {

  /**
   * Longest summary, in characters.
   */
  private const SUMMARY_LENGTH = 240;

  /**
   * Plain text from HTML, or from a service's encoded HTML.
   */
  public static function clean(?string $text): string {
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Encoded twice by some services, so a tag can only show up after decoding.
    $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
  }

  /**
   * Shortens plain text at a word, marking that it was cut.
   */
  public static function summary(?string $text): string {
    $text = self::clean($text);
    if (mb_strlen($text) <= self::SUMMARY_LENGTH) {
      return $text;
    }
    $cut = mb_substr($text, 0, self::SUMMARY_LENGTH);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space ? mb_substr($cut, 0, $space) : $cut, " ,;:.") . '…';
  }

  /**
   * An address that's safe in an href: web addresses and site paths only.
   */
  public static function url(string $url): string {
    $url = trim($url);
    return preg_match('#^(https?://|/(?!/))#i', $url) ? $url : '#';
  }

  /**
   * Escapes plain text and marks the searched words at their starts.
   *
   * Works on plain text, never rendered HTML, so a word can't land inside a
   * tag or an attribute. Marks go in as placeholders before escaping, so a
   * search for "amp" can't mark the inside of an &amp;.
   *
   * Empty text comes back as '', because Markup::create() returns that as is.
   */
  public static function highlight(string $text, array $words): MarkupInterface|string {
    $words = array_map(static fn(string $word): string => preg_quote($word, '/'), $words);
    if ($words) {
      usort($words, static fn(string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
      $marked = preg_replace('/(?<![\p{L}\p{N}])(' . implode('|', $words) . ')/iu', "\x01$1\x02", $text);
      // A bad UTF-8 sequence gives NULL: keep the text, unmarked.
      $text = $marked ?? $text;
    }
    $safe = strtr(Html::escape($text), ["\x01" => '<mark>', "\x02" => '</mark>']);
    return Markup::create($safe);
  }

}

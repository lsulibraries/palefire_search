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
   * A form's starter text, saved by editors who left it in place. Not content.
   */
  private const PLACEHOLDERS = ['Enter short description here.'];

  /**
   * Plain text from HTML, or from a service's encoded HTML.
   */
  public static function clean(?string $text): string {
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Encoded twice by some services, so a tag can only show up after decoding.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // A space where a block ends, or list items would run together as one word.
    $text = strip_tags(preg_replace('#</?(p|div|li|ul|ol|br|h[1-6]|tr|td|blockquote)\b#i', ' $0', $text) ?? $text);
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
  }

  /**
   * Whether text is a form's starter text rather than content.
   */
  public static function isPlaceholder(string $text): bool {
    return in_array(trim($text), self::PLACEHOLDERS, TRUE);
  }

  /**
   * Shortens plain text at a word, marking that it was cut.
   */
  public static function summary(?string $text): string {
    $text = self::clean($text);
    if (self::isPlaceholder($text)) {
      return '';
    }
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

  /**
   * A cover's address, over https so a secure page has no mixed content, or
   * NULL. One that doesn't answer over https is removed by the page's script.
   */
  public static function imageUrl(string $url): ?string {
    $url = trim($url);
    if (str_starts_with($url, '//')) {
      $url = 'https:' . $url;
    }
    $url = preg_replace('#^http://#i', 'https://', $url);
    return preg_match('#^(https://|/[^/])#i', $url) ? $url : NULL;
  }

}

<?php

namespace Drupal\palefire_search;

/**
 * Short answers above the results, for searches that ask about a service.
 *
 * Matched on the whole search, not a word in it: "hours" and "library hours"
 * get today's hours, "book of hours" doesn't. Each answer lists the phrases
 * that bring it up, lower case, without punctuation. Add one here.
 */
final class SearchAnswers {

  /**
   * The answers, by key.
   *
   * - phrases: whole searches that bring it up.
   * - title: its heading.
   * - locations: LibCal locations for today's hours, the same as the Hours
   *   drawer's (the theme's utility-panels.html.twig). name is empty to use
   *   LibCal's.
   * - links: [label, url, external].
   */
  private const ANSWERS = [
    'hours' => [
      'phrases' => [
        'hours',
        'hour',
        'hours today',
        'todays hours',
        'opening hours',
        'open hours',
        'building hours',
        'library hours',
        'libraries hours',
        'lsu library hours',
        'lsu libraries hours',
        'main library hours',
        'middleton library hours',
        'hill memorial hours',
        'hill memorial library hours',
        'special collections hours',
        'when is the library open',
        'is the library open',
        'library open',
        'library open today',
        'when does the library close',
        'when does the library open',
        'library closing time',
      ],
      'title' => 'Library hours',
      'locations' => [
        ['lid' => '241', 'name' => 'Main Library'],
        ['lid' => '236', 'name' => ''],
      ],
      'links' => [
        ['See full hours', 'https://lsu.libcal.com/hours', TRUE],
      ],
    ],
  ];

  /**
   * The answer for a search, or NULL.
   */
  public static function find(string $query): ?array {
    $words = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', '', mb_strtolower($query))));
    if ($words === '') {
      return NULL;
    }
    foreach (self::ANSWERS as $key => $answer) {
      if (in_array($words, $answer['phrases'], TRUE)) {
        return ['key' => $key] + $answer;
      }
    }
    return NULL;
  }

}

<?php

/**
 * @file
 * Creates the Combined Search feedback Webform, combined_search_feedback.
 *
 * The search page asks "Did you find what you needed?" under the results once
 * this form exists and is open. Each answer links here with the search filled
 * in: the form takes its values from the address (form_prepopulate), so the
 * keys below are the ones the page sends. Rename one and the page stops
 * filling it in.
 *
 * Usage, from the site's web folder:
 *   drush php:script modules/custom/palefire_search/scripts/create_feedback_webform.php
 *   drush php:script modules/custom/palefire_search/scripts/create_feedback_webform.php -- apply=1
 *   drush php:script modules/custom/palefire_search/scripts/create_feedback_webform.php -- apply=1 email=group@example.edu
 *
 * A dry run unless apply=1. email= also sends each response to that address,
 * replying to the visitor when they gave theirs. Responses and their
 * screenshots are deleted after 365 days; purge_days=N changes that, and
 * purge_days=0 keeps them. If the form already exists it
 * is left alone, so edits made in the UI are kept. replace=1 resets its fields
 * and settings to these (submissions are kept).
 *
 * Then: drush cr
 */

use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;

if (PHP_SAPI !== 'cli') {
  return;
}

$args = [];
foreach ((isset($extra) && is_array($extra)) ? $extra : [] as $arg) {
  if (str_contains((string) $arg, '=')) {
    [$k, $v] = explode('=', (string) $arg, 2);
    $args[trim($k)] = trim($v);
  }
}
$apply = ($args['apply'] ?? '') === '1';
$replace = ($args['replace'] ?? '') === '1';
$email = $args['email'] ?? '';
$purge_days = (int) ($args['purge_days'] ?? 365);
$prefix = $apply ? '' : '[dry run] ';

$id = 'combined_search_feedback';
$path = '/v2/search/feedback';

if (!\Drupal::moduleHandler()->moduleExists('webform')) {
  echo "The Webform module isn't enabled. Nothing done.\n";
  return;
}
if ($email !== '' && !\Drupal::service('email.validator')->isValid($email)) {
  echo "email= isn't an email address: $email. Nothing done.\n";
  return;
}

$existing = Webform::load($id);
if ($existing && !$replace) {
  echo "$id already exists (" . $existing->toUrl()->toString() . "). Left as it is; replace=1 resets it.\n";
  return;
}

// Every field optional: a single click on Yes, Partly or No is already an
// answer worth keeping. Vendor names stay out, so the form outlasts the
// systems behind the search.
$elements = [
  // First, so people know they can be candid. True for everyone: no IP
  // address (form_remote_addr below), and palefire_search.module drops a
  // signed-in visitor's account from each response.
  'intro' => [
    '#type' => 'webform_markup',
    '#markup' => '<p><strong>Your feedback is anonymous.</strong> We don’t record your name, your account or your IP address, only the search you came from. Short notes and blunt ones are both welcome.</p>',
  ],
  'found' => [
    '#type' => 'radios',
    '#title' => 'Did you find what you were looking for?',
    '#options' => [
      'yes' => 'Yes',
      'partly' => 'Partly',
      'no' => 'No',
    ],
    '#options_display' => 'side_by_side',
  ],
  'looking_for' => [
    '#type' => 'textfield',
    '#title' => 'What were you looking for?',
    '#description' => 'Filled in with your search. Change it if you were after something else.',
    '#maxlength' => 255,
  ],
  'what_would_help' => [
    '#type' => 'textarea',
    '#title' => 'What got in the way, or what would make it better?',
    '#rows' => 4,
    '#maxlength' => 2000,
  ],
  'compared' => [
    '#type' => 'radios',
    '#title' => 'Compared with the current library search, this is',
    '#options' => [
      'better' => 'Better',
      'same' => 'About the same',
      'worse' => 'Worse',
      'not_used' => 'I haven’t used the current one',
    ],
  ],
  'role' => [
    '#type' => 'radios',
    '#title' => 'Which describes you best?',
    '#options' => [
      'undergraduate' => 'Undergraduate student',
      'graduate' => 'Graduate student',
      'faculty' => 'Faculty',
      'staff' => 'LSU staff',
      'library_staff' => 'Library staff',
      'visitor' => 'Visitor or community member',
    ],
  ],
  // Private files only: a screenshot can show someone's own account. Left
  // out below when the site has no private file directory.
  'screenshot' => [
    '#type' => 'webform_image_file',
    '#title' => 'A screenshot, if it helps',
    '#description' => 'Optional. One image: PNG, JPG or WebP, up to 5 MB. Please leave out anything personal.',
    '#file_extensions' => 'png jpg jpeg webp',
    '#max_filesize' => 5,
    '#uri_scheme' => 'private',
    '#multiple' => FALSE,
  ],
  'email' => [
    '#type' => 'email',
    '#title' => 'Your email, if you’d like a reply',
    '#autocomplete' => 'email',
  ],
  'privacy' => [
    '#type' => 'webform_markup',
    '#markup' => '<p>We keep what you write and the search you came from'
    . ($purge_days > 0 ? ' for ' . ($purge_days === 365 ? 'a year' : "$purge_days days") : '')
    . ', only to improve Combined Search. Leave your email only if you’d like a reply: it’s used for nothing else.</p>',
  ],
  // Sent by the search page, never shown. Their keys are the address's.
  'search_terms' => ['#type' => 'hidden', '#title' => 'Search terms'],
  'search_mode' => ['#type' => 'hidden', '#title' => 'Search tab'],
  'result_count' => ['#type' => 'hidden', '#title' => 'Results'],
  'sources_shown' => ['#type' => 'hidden', '#title' => 'Sources shown'],
  'filters_chosen' => ['#type' => 'hidden', '#title' => 'Filters chosen'],
  'screen' => ['#type' => 'hidden', '#title' => 'Screen size'],
  'actions' => [
    '#type' => 'webform_actions',
    '#submit__label' => 'Send feedback',
  ],
];
// Only these take values from the address. The rest start empty.
foreach (['found', 'looking_for', 'search_terms', 'search_mode', 'result_count', 'sources_shown', 'filters_chosen', 'screen'] as $key) {
  $elements[$key]['#prepopulate'] = TRUE;
}

$settings = [
  'page' => TRUE,
  'page_submit_path' => $path,
  // The search page's drawer (the theme's index-search.js) fetches this page
  // and posts the form itself. Webform's Ajax would bring jQuery for nothing.
  'ajax' => FALSE,
  'form_remote_addr' => FALSE,
  'form_previous_submissions' => FALSE,
  'confirmation_type' => 'inline',
  'confirmation_title' => 'Thank you',
  // Shown in place of the form, in the search page's drawer as on the form's
  // own page: no link away from the search.
  'confirmation_message' => '<p>Thank you. Your note goes straight to the people building Combined Search.</p>',
  'confirmation_back' => FALSE,
  // Responses, and the screenshots with them, are deleted after this many
  // days. purge_days=0 keeps them.
  'purge' => $purge_days > 0 ? 'all' : 'none',
  'purge_days' => $purge_days > 0 ? $purge_days : NULL,
];

if (!\Drupal::service('stream_wrapper_manager')->isValidScheme('private')) {
  unset($elements['screenshot']);
  echo "{$prefix}No private file directory on this site: the screenshot field is left out. Set \$settings['file_private_path'] and run again with replace=1 to add it.\n";
}

echo "{$prefix}" . ($existing ? 'Resetting' : 'Creating') . " $id at $path\n";
echo "{$prefix}Fields: " . implode(', ', array_keys(array_diff_key($elements, ['actions' => 1, 'privacy' => 1, 'intro' => 1]))) . "\n";

$modules = \Drupal::moduleHandler();
$spam = [];
if ($modules->moduleExists('honeypot')) {
  $spam[] = 'honeypot';
}
if ($modules->moduleExists('antibot')) {
  $spam[] = 'antibot';
}
echo "{$prefix}Spam protection: " . ($spam ? implode(', ', $spam) : 'none (neither Honeypot nor Antibot is enabled)') . "\n";
echo "{$prefix}Deleted after: " . ($purge_days > 0 ? "$purge_days days" : 'never') . "\n";
echo "{$prefix}Email: " . ($email !== '' ? $email : 'none (responses are in the Webform\'s Results tab)') . "\n";

if (!$apply) {
  echo "Dry run. Add apply=1 to make it.\n";
  return;
}

$webform = $existing ?: Webform::create(['id' => $id]);
$webform->set('title', 'Combined Search feedback');
$webform->set('description', 'Linked from under the results at /v2/search, with the search filled in. Created by palefire_search/scripts/create_feedback_webform.php.');
$webform->set('category', 'Palefire');
$webform->setStatus(WebformInterface::STATUS_OPEN);
$webform->setElements($elements);
foreach ($settings as $key => $value) {
  $webform->setSetting($key, $value);
}
if (in_array('honeypot', $spam, TRUE)) {
  $webform->setThirdPartySetting('honeypot', 'honeypot', TRUE);
  // No time limit: Yes, then Send, a second later, is a real answer.
  $webform->setThirdPartySetting('honeypot', 'time_restriction', FALSE);
}
if (in_array('antibot', $spam, TRUE)) {
  $webform->setThirdPartySetting('antibot', 'antibot', TRUE);
}
$webform->save();

if ($email !== '') {
  $handler_id = 'email_feedback';
  $configuration = [
    'id' => 'email',
    'handler_id' => $handler_id,
    'label' => 'Email feedback',
    'status' => TRUE,
    'weight' => 0,
    'settings' => [
      'to_mail' => $email,
      'from_mail' => '_default',
      'reply_to' => '[webform_submission:values:email:raw]',
      'subject' => 'Combined Search feedback: [webform_submission:values:found]',
      'body' => '_default',
    ],
  ];
  if ($webform->getHandlers()->has($handler_id)) {
    $webform->deleteWebformHandler($webform->getHandler($handler_id));
  }
  $handler = \Drupal::service('plugin.manager.webform.handler')->createInstance('email', $configuration);
  $webform->addWebformHandler($handler);
}

echo "Done: " . Webform::load($id)->toUrl()->setAbsolute()->toString() . "\n";

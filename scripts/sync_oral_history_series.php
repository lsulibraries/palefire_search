<?php

/**
 * @file
 * Brings oral history records' Series into line with OralHistorySeries.
 *
 * To rename a series: change its name in OralHistorySeries::FOLDERS, then run
 * this. A record with an abstract in a mapped folder takes that folder's
 * name. A record without one keeps its series unless it's the same name
 * written another way (case or spacing), as "Robert Ray Cox series" is
 * "Robert Ray Cox Series". Only field_series changes, in a new revision.
 *
 * Usage, from the site's web folder:
 *   drush php:script modules/custom/palefire_search/scripts/sync_oral_history_series.php
 *   drush php:script modules/custom/palefire_search/scripts/sync_oral_history_series.php -- apply=1
 *
 * A dry run unless apply=1. Then reindex: drush search-api:index bento_search
 */

use Drupal\node\Entity\Node;
use Drupal\palefire_search\OralHistorySeries;

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
$prefix = $apply ? '' : '[dry run] ';

// Each name the same however it's capitalized or spaced.
$plain = static fn(string $name): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
$names = [];
foreach (OralHistorySeries::FOLDERS as $name) {
  $names[$plain($name)] = $name;
}

$database = \Drupal::database();
$column = $database->schema()->fieldExists('node__field_record_url', 'field_record_url_uri') ? 'field_record_url_uri' : 'field_record_url_value';
$query = $database->select('node__field_record_type', 't');
$query->condition('t.bundle', 'archive_record')->condition('t.field_record_type_value', 'oral_history');
$query->addField('t', 'entity_id', 'nid');
$query->leftJoin('node__field_series', 's', 's.entity_id = t.entity_id');
$query->addField('s', 'field_series_value', 'series');
$query->leftJoin('node__field_record_url', 'u', 'u.entity_id = t.entity_id');
$query->addField('u', $column, 'url');

$changes = [];
foreach ($query->execute() as $row) {
  $current = (string) $row->series;
  $folder = OralHistorySeries::folderOf(rawurldecode((string) $row->url));
  // Folders match whatever their case: WJBO is wjbo in some links.
  $wanted = NULL;
  foreach (OralHistorySeries::FOLDERS as $key => $name) {
    if ($folder !== NULL && strcasecmp($key, $folder) === 0) {
      $wanted = $name;
    }
  }
  $wanted ??= $names[$plain($current)] ?? NULL;
  if ($wanted !== NULL && $wanted !== $current) {
    $changes[(int) $row->nid] = [$current, $wanted];
  }
}

if (!$changes) {
  echo 'Every oral history record already has its series as OralHistorySeries names it.' . PHP_EOL;
  return;
}

// Said once for each rename, with how many records it changes.
$renames = [];
foreach ($changes as [$from, $to]) {
  $label = sprintf('"%s" to "%s"', $from === '' ? '(none)' : $from, $to);
  $renames[$label] = ($renames[$label] ?? 0) + 1;
}
foreach ($renames as $label => $count) {
  echo "{$prefix}$label: $count record" . ($count === 1 ? '' : 's') . PHP_EOL;
}

if (!$apply) {
  echo PHP_EOL . 'Dry run: nothing saved. Run again with apply=1 to save.' . PHP_EOL;
  return;
}
foreach ($changes as $nid => [, $to]) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }
  $node->set('field_series', $to);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Series named as in OralHistorySeries.');
  $node->save();
}
echo PHP_EOL . 'Saved ' . count($changes) . ' records. Now reindex: drush search-api:index bento_search' . PHP_EOL;

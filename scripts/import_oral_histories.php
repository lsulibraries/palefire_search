<?php

/**
 * @file
 * Adds the Catalog's oral history interviews the site has no record for.
 *
 * For each series in OralHistorySeries::FOLDERS, the Catalog is searched for
 * the interviews whose abstract PDF is in that series' folder. Each abstract
 * with no archive record on the site gets one: an oral history with the
 * interview's name and tape numbers as its title, the PDF as its link, the
 * series, and the Catalog's summary, interviewee and interviewer where the
 * site has those fields. Existing records are never changed.
 *
 * Usage, from the site's web folder:
 *   drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php
 *   drush php:script modules/custom/palefire_search/scripts/import_oral_histories.php -- apply=1
 *
 * A dry run unless apply=1. Optional: folder=<name> for one series only.
 * Then reindex: drush search-api:index bento_search
 */

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\palefire_search\OralHistorySeries;
use Drupal\palefire_search\ResultText;

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
$only = $args['folder'] ?? '';
$prefix = $apply ? '' : '[dry run] ';

const PF_OH_FEED = 'https://lsu.ent.sirsi.net/client/rss/hitlist/lsu/qu=';
const PF_OH_SERVICE = 'https://lalu.sirsi.net/lalu_ilsws/rest/standard/lookupTitleInfo?clientID=DS_CLIENT&json=true&includeCatalogingInfo=true&marcEntryFilter=ALL&titleID=';

$client = \Drupal::httpClient();
$get = static function (string $url) use ($client): ?string {
  try {
    $response = $client->request('GET', $url, ['timeout' => 30, 'http_errors' => FALSE, 'headers' => ['User-Agent' => 'LSU Libraries search']]);
    return $response->getStatusCode() === 200 ? (string) $response->getBody() : NULL;
  }
  catch (\Throwable $e) {
    return NULL;
  }
};
// A PDF's site path, the same however a link writes it.
$key = static function (string $url): ?string {
  $url = preg_replace('#^internal:#', '', trim($url)) ?? $url;
  $path = ResultText::archivePdf(rawurldecode($url));
  return $path === NULL ? NULL : mb_strtolower($path);
};

// Every PDF the site's archive records already link.
$database = \Drupal::database();
$column = $database->schema()->fieldExists('node__field_record_url', 'field_record_url_uri') ? 'field_record_url_uri' : 'field_record_url_value';
$known = [];
foreach ($database->select('node__field_record_url', 'u')->fields('u', ['entity_id', $column])->condition('u.bundle', 'archive_record')->execute() as $row) {
  if ($path = $key((string) $row->{$column})) {
    $known[$path] = (int) $row->entity_id;
  }
}
echo 'The site links ' . count($known) . ' finding aid and oral history PDFs.' . PHP_EOL . PHP_EOL;

// What the site's archive records can hold.
$definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'archive_record');
// A record type may be a list field: its value is set the same way.
$texts = ['string', 'string_long', 'list_string', 'text', 'text_long', 'text_with_summary'];
$set = static function (NodeInterface $node, string $field, array $values) use ($definitions, $texts): void {
  $values = array_values(array_filter(array_map('trim', $values), 'strlen'));
  if (!$values || !isset($definitions[$field]) || !in_array($definitions[$field]->getType(), $texts, TRUE)) {
    return;
  }
  $cardinality = $definitions[$field]->getFieldStorageDefinition()->getCardinality();
  $values = $cardinality > 0 ? array_slice($values, 0, $cardinality) : $values;
  $formatted = !in_array($definitions[$field]->getType(), ['string', 'string_long', 'list_string'], TRUE);
  $node->set($field, array_map(static fn(string $value): array => $formatted ? ['value' => $value, 'format' => 'plain_text'] : ['value' => $value], $values));
};

$fills = [];
foreach (['field_record_type', 'field_record_url', 'field_series', 'field_summary', 'field_interviewee', 'field_interviewer'] as $field) {
  $fills[] = $field . ' (' . (isset($definitions[$field]) ? $definitions[$field]->getType() : 'not on archive records, left out') . ')';
}
echo 'New records fill: ' . implode(', ', $fills) . PHP_EOL . PHP_EOL;
if (!isset($definitions['field_record_type'], $definitions['field_record_url'], $definitions['field_series'])) {
  echo 'Archive records need field_record_type, field_record_url and field_series. Nothing done.' . PHP_EOL;
  return;
}

// The Catalog's details for a record, kept for its other abstracts.
$details = [];
$detailsOf = static function (string $id) use ($get, &$details): array {
  if (isset($details[$id])) {
    return $details[$id];
  }
  $found = ['title' => '', 'summary' => [], 'interviewers' => []];
  $json = $get(PF_OH_SERVICE . rawurlencode($id));
  $title = $json ? (json_decode($json, TRUE)['TitleInfo'][0] ?? []) : [];
  foreach ($title['BibliographicInfo']['MarcEntryInfo'] ?? [] as $entry) {
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) ($entry['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    match ((string) ($entry['entryID'] ?? '')) {
      '245' => $found['title'] = $text,
      '520' => $found['summary'][] = $text,
      // "Bourgeois, Joanne interviewer." as Joanne Bourgeois.
      '700' => preg_match('/^(.+?),\s*([^,]+?)(?:,\s*[\d-]+)?,?\s+interviewer\.?$/u', $text, $name) ? $found['interviewers'][] = $name[2] . ' ' . $name[1] : NULL,
      default => NULL,
    };
  }
  return $details[$id] = $found;
};

$folders = $only !== '' ? array_intersect_key(OralHistorySeries::FOLDERS, [$only => TRUE]) : OralHistorySeries::FOLDERS;
if (!$folders) {
  echo "No series uses the folder \"$only\"." . PHP_EOL;
  return;
}

$added = 0;
$failed = [];
foreach ($folders as $folder => $series) {
  $feed = $get(PF_OH_FEED . rawurlencode('oralhistory ' . $folder));
  $xml = $feed ? @simplexml_load_string($feed) : FALSE;
  if (!$xml) {
    $failed[] = $folder;
    echo "$folder: the Catalog couldn't be read. Skipped." . PHP_EOL;
    continue;
  }
  $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');

  // Each abstract in this folder, with the record that links it.
  $abstracts = [];
  foreach ($xml->xpath('//a:entry') ?: [] as $entry) {
    $entry->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
    $id = preg_match('/:(\d+)$/', (string) ($entry->xpath('a:id')[0] ?? ''), $m) ? $m[1] : NULL;
    $content = html_entity_decode((string) ($entry->xpath('a:content')[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('#href="([^"]+\.pdf)"#i', $content, $links);
    foreach ($links[1] as $link) {
      $path = $key($link);
      if ($id && $path && strcasecmp((string) OralHistorySeries::folderOf($path), $folder) === 0) {
        $abstracts[$path] = ['id' => $id, 'url' => 'https://www.lib.lsu.edu' . ResultText::archivePdf(rawurldecode(preg_replace('#^internal:#', '', $link)))];
      }
    }
  }
  $missing = array_diff_key($abstracts, $known);
  echo sprintf('%s (%s): %d in the Catalog, %d already on the site, %d to add.', $folder, $series, count($abstracts), count($abstracts) - count($missing), count($missing)) . PHP_EOL;

  foreach ($missing as $path => $abstract) {
    $catalog = $detailsOf($abstract['id']);
    // "Joseph Fedele oral history interview, 2018." with its PDF's tapes:
    // Joseph Fedele T4947, as the site's records are titled.
    $name = trim(preg_replace('/\s+oral history interviews?\b.*$/iu', '', $catalog['title']) ?? '') ?: pathinfo($abstract['url'], PATHINFO_FILENAME);
    preg_match_all('/(?<![A-Za-z0-9])T\d+/', basename($abstract['url']), $tapes);
    $title = trim($name . ' ' . implode(' ', array_unique($tapes[0])));
    echo "  {$prefix}add \"$title\" — {$abstract['url']} (Catalog record {$abstract['id']})" . PHP_EOL;
    if (!$apply) {
      continue;
    }
    $node = Node::create(['type' => 'archive_record', 'title' => mb_substr($title, 0, 255), 'status' => 1, 'uid' => 1]);
    $set($node, 'field_record_type', ['oral_history']);
    $set($node, 'field_series', [$series]);
    $set($node, 'field_summary', [implode(' ', $catalog['summary'])]);
    $set($node, 'field_interviewee', [$name]);
    $set($node, 'field_interviewer', $catalog['interviewers']);
    if (isset($definitions['field_record_url'])) {
      $node->set('field_record_url', $definitions['field_record_url']->getType() === 'link' ? ['uri' => $abstract['url']] : ['value' => $abstract['url']]);
    }
    // Not an oral history, it would be lost among the archive records.
    if ($node->get('field_record_type')->value !== 'oral_history') {
      echo "  Couldn't set the record type. Stopped before saving \"$title\"." . PHP_EOL;
      return;
    }
    $node->save();
    $known[$path] = (int) $node->id();
    $added++;
  }
}

echo PHP_EOL;
if ($failed) {
  echo 'Not read, run again later: ' . implode(', ', $failed) . PHP_EOL;
}
if (!$apply) {
  echo 'Dry run: nothing saved. Run again with apply=1 to add them.' . PHP_EOL;
  return;
}
echo "Added $added oral history records. Now reindex: drush search-api:index bento_search" . PHP_EOL;

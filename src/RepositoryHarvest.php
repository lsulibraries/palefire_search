<?php

namespace Drupal\palefire_search;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Copies the Scholarly Repository's records into this site, over OAI-PMH.
 *
 * The repository's search can't be asked from this server (its firewall
 * answers with a challenge), but its OAI-PMH feed can. The feed has each
 * record's title, authors, abstract, keywords, type and date, not the text of
 * its files, so RepositorySearch matches those.
 *
 * A run reads the feed a page (100 records) at a time and saves where it got
 * to after each page, so a run that stops resumes at the next. The first run
 * reads everything; later ones only what changed since the last finished run.
 * The repository doesn't report deleted records, so a full run (--full, or
 * monthly from cron) drops any record it no longer finds.
 */
class RepositoryHarvest {

  /**
   * The feed, and the format read from it: the only one with each record's
   * repository address and its full abstract.
   */
  public const FEED = 'https://repository.lsu.edu/do/oai/';
  private const FORMAT = 'qualified-dublin-core';

  /**
   * The table the records go in.
   */
  public const TABLE = 'palefire_search_repository';

  /**
   * Only these addresses are a record's page.
   */
  private const RECORD_URL = '#^https://repository\.lsu\.edu/[^\s"<>]+$#';

  /**
   * Seconds between pages, to stay a light visitor; tries per page.
   */
  private const PAUSE = 1;
  private const TRIES = 3;

  /**
   * How often a cron run checks for changes, and does a full run, in seconds.
   */
  private const DAILY = 86400;
  private const MONTHLY = 2592000;

  /**
   * Pages a cron run reads, so it stays short. Drush reads them all.
   */
  public const CRON_PAGES = 5;

  private const LOCK = 'palefire_search_repository_harvest';
  private const STATE_RUN = 'palefire_search.repository.run';
  private const STATE_LAST = 'palefire_search.repository.last';
  private const STATE_SETS = 'palefire_search.repository.sets';

  private const NS = [
    'oai' => 'http://www.openarchives.org/OAI/2.0/',
    'dc' => 'http://purl.org/dc/elements/1.1/',
  ];

  public function __construct(
    private readonly ClientInterface $client,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reads pages of the feed into the table.
   *
   * @param int $pages
   *   Most pages to read, 0 for no limit.
   * @param bool $full
   *   Start a full run over, even if one is under way or another is done.
   * @param callable|null $report
   *   Called after each page with (records read so far, records in all or
   *   NULL), for progress.
   *
   * @return array
   *   pages, records (saved this time), done (the run finished), failed
   *   (the feed couldn't be read), locked (another run is going).
   */
  public function harvest(int $pages = 0, bool $full = FALSE, ?callable $report = NULL): array {
    $result = ['pages' => 0, 'records' => 0, 'done' => FALSE, 'failed' => FALSE, 'locked' => FALSE];
    if (!$this->lock->acquire(self::LOCK, 600)) {
      $result['locked'] = TRUE;
      return $result;
    }

    try {
      $run = $this->state->get(self::STATE_RUN);
      $last = $this->state->get(self::STATE_LAST);
      if ($full || !$run) {
        $is_full = $full || empty($last['response']);
        $run = [
          'started' => time(),
          'full' => $is_full,
          'from' => $is_full ? NULL : $last['response'],
          'response' => NULL,
          'token' => NULL,
        ];
        $sets = $this->setNames();
        if ($sets !== NULL) {
          $this->state->set(self::STATE_SETS, $sets);
        }
      }
      $sets = $this->state->get(self::STATE_SETS, []);

      while ($pages === 0 || $result['pages'] < $pages) {
        $query = $run['token'] !== NULL
          ? ['verb' => 'ListRecords', 'resumptionToken' => $run['token']]
          : array_filter(['verb' => 'ListRecords', 'metadataPrefix' => self::FORMAT, 'from' => $run['from']]);
        $page = $this->page($query);
        if ($page === NULL) {
          $result['failed'] = TRUE;
          break;
        }
        if ($page['error'] === 'badResumptionToken') {
          // The repository forgot where the run was: begin it again.
          $this->loggerFactory->get('palefire_search')->warning('Repository harvest: the feed no longer knows the run’s place, so it starts over.');
          $run['token'] = NULL;
          $run['response'] = NULL;
          continue;
        }
        if ($page['error'] !== NULL && $page['error'] !== 'noRecordsMatch') {
          $this->loggerFactory->get('palefire_search')->error('Repository harvest: the feed answered @code.', ['@code' => $page['error']]);
          $result['failed'] = TRUE;
          break;
        }

        // The feed's own time at the start of the run: the next run asks for
        // what changed since then.
        $run['response'] ??= $page['response'];
        foreach ($page['records'] as $record) {
          $this->save($record, $sets, $run['started']);
        }
        $result['records'] += count($page['records']);
        $result['pages']++;
        $run['token'] = $page['token'];
        $this->state->set(self::STATE_RUN, $run);
        if ($report) {
          $report($page['cursor'] + count($page['records']), $page['size']);
        }

        if ($page['token'] === NULL) {
          $this->finish($run);
          $result['done'] = TRUE;
          break;
        }
        // Keep the lock while the run goes on, and go easy on the repository.
        $this->lock->acquire(self::LOCK, 600);
        sleep(self::PAUSE);
      }
    }
    finally {
      $this->lock->release(self::LOCK);
    }
    return $result;
  }

  /**
   * A cron run: a few pages of any run under way, else a new run when one is
   * due (changes daily, everything monthly).
   */
  public function cron(): void {
    $run = $this->state->get(self::STATE_RUN);
    $last = $this->state->get(self::STATE_LAST);
    if (!$run && !empty($last['finished']) && time() - $last['finished'] < self::DAILY) {
      return;
    }
    $full = !$run && (empty($last['full_finished']) || time() - $last['full_finished'] >= self::MONTHLY);
    $this->harvest(self::CRON_PAGES, $full);
  }

  /**
   * Where things stand: records held, the last finished run, any run going.
   */
  public function status(): array {
    return [
      'records' => (int) $this->database->select(self::TABLE)->countQuery()->execute()->fetchField(),
      'last' => $this->state->get(self::STATE_LAST),
      'run' => $this->state->get(self::STATE_RUN),
    ];
  }

  /**
   * Ends a run: remembers when, and after a full one drops what it didn't see.
   */
  private function finish(array $run): void {
    $last = $this->state->get(self::STATE_LAST, []);
    $last['response'] = $run['response'] ?? $last['response'] ?? NULL;
    $last['finished'] = time();
    if ($run['full']) {
      $dropped = $this->database->delete(self::TABLE)->condition('harvested', $run['started'], '<')->execute();
      $last['full_finished'] = time();
      if ($dropped) {
        $this->loggerFactory->get('palefire_search')->notice('Repository harvest: dropped @count records the repository no longer lists.', ['@count' => $dropped]);
      }
    }
    $this->state->set(self::STATE_LAST, $last);
    $this->state->delete(self::STATE_RUN);
  }

  /**
   * Saves one record, or removes it if the feed says it's gone.
   */
  private function save(array $record, array $sets, int $started): void {
    if ($record['deleted'] || $record['url'] === '' || $record['title'] === '') {
      $this->database->delete(self::TABLE)->condition('oai_id', $record['id'])->execute();
      return;
    }
    $this->database->merge(self::TABLE)
      ->key('oai_id', $record['id'])
      ->fields([
        'url' => mb_substr($record['url'], 0, 1024),
        'title' => $record['title'],
        'creators' => implode("\n", $record['creators']),
        'abstract' => $record['abstract'],
        'subjects' => implode("\n", $record['subjects']),
        'kind' => mb_substr($record['kind'], 0, 255),
        'publication' => mb_substr($sets[$record['set']] ?? $record['publication'], 0, 255),
        'date' => mb_substr($record['date'], 0, 32),
        'year' => preg_match('/^([12]\d{3})/', $record['date'], $match) ? (int) $match[1] : NULL,
        'harvested' => $started,
      ])
      ->execute();
  }

  /**
   * One page of the feed, or NULL if it couldn't be read.
   *
   * @return array|null
   *   response (the feed's time), error (its error code or NULL), records,
   *   token (NULL on the last page), cursor, size (records in all, or NULL).
   */
  private function page(array $query): ?array {
    $xpath = $this->fetch($query);
    if (!$xpath) {
      return NULL;
    }
    $error = $xpath->query('/oai:OAI-PMH/oai:error')->item(0);
    $token = $xpath->query('//oai:resumptionToken')->item(0);
    $records = [];
    foreach ($xpath->query('//oai:ListRecords/oai:record') as $node) {
      $records[] = $this->record($xpath, $node);
    }
    $size = $token instanceof \DOMElement ? $token->getAttribute('completeListSize') : '';
    return [
      'response' => trim((string) $xpath->query('/oai:OAI-PMH/oai:responseDate')->item(0)?->textContent),
      'error' => $error instanceof \DOMElement ? $error->getAttribute('code') : NULL,
      'records' => $records,
      'token' => $token && trim($token->textContent) !== '' ? trim($token->textContent) : NULL,
      'cursor' => $token instanceof \DOMElement ? (int) $token->getAttribute('cursor') : 0,
      'size' => $size !== '' ? (int) $size : NULL,
    ];
  }

  /**
   * One record from the feed as plain values.
   */
  private function record(\DOMXPath $xpath, \DOMNode $node): array {
    $all = static function (string $name) use ($xpath, $node): array {
      $values = [];
      foreach ($xpath->query('oai:metadata/*/dc:' . $name, $node) as $element) {
        $value = ResultText::clean($element->textContent);
        if ($value !== '') {
          $values[] = $value;
        }
      }
      return array_values(array_unique($values));
    };
    $id = trim((string) $xpath->query('oai:header/oai:identifier', $node)->item(0)?->textContent);
    $header = $xpath->query('oai:header', $node)->item(0);
    $url = '';
    foreach ($all('identifier') as $identifier) {
      if (preg_match(self::RECORD_URL, $identifier)) {
        $url = $identifier;
        break;
      }
    }
    // A thesis says what kind in its degree level; anything else in its type.
    $level = $all('thesis.degree.level');
    $type = $all('type');
    return [
      'id' => mb_substr($id, 0, 191),
      'deleted' => $header instanceof \DOMElement && $header->getAttribute('status') === 'deleted',
      'url' => $url,
      'title' => $all('title')[0] ?? '',
      'creators' => $all('creator'),
      'abstract' => $all('description.abstract')[0] ?? $all('description')[0] ?? '',
      'subjects' => $all('subject'),
      'kind' => $level[0] ?? $type[0] ?? '',
      // The series is named by the identifier's middle part
      // (oai:repository.lsu.edu:gradschool_dissertations-1002); a thesis
      // without one in the set list falls back to its department.
      'set' => preg_match('/:([^:]+)-\d+$/', $id, $match) ? $match[1] : '',
      'publication' => $all('thesis.degree.discipline')[0] ?? $all('publisher')[0] ?? '',
      'date' => $all('date.created')[0] ?? $all('date')[0] ?? '',
    ];
  }

  /**
   * Each series' name by its key (gradschool_dissertations => LSU Doctoral
   * Dissertations), or NULL if the list couldn't be read.
   */
  private function setNames(): ?array {
    $names = [];
    $query = ['verb' => 'ListSets'];
    // A guard against a list that never ends.
    for ($i = 0; $i < 100; $i++) {
      $xpath = $this->fetch($query);
      if (!$xpath) {
        return NULL;
      }
      foreach ($xpath->query('//oai:ListSets/oai:set') as $set) {
        $spec = trim((string) $xpath->query('oai:setSpec', $set)->item(0)?->textContent);
        $name = ResultText::clean($xpath->query('oai:setName', $set)->item(0)?->textContent);
        if (str_starts_with($spec, 'publication:') && $name !== '') {
          $names[substr($spec, strlen('publication:'))] = $name;
        }
      }
      $token = trim((string) $xpath->query('//oai:resumptionToken')->item(0)?->textContent);
      if ($token === '') {
        return $names;
      }
      $query = ['verb' => 'ListSets', 'resumptionToken' => $token];
    }
    return $names;
  }

  /**
   * The feed's answer to one request, ready to query, or NULL.
   */
  private function fetch(array $query): ?\DOMXPath {
    for ($try = 1; $try <= self::TRIES; $try++) {
      try {
        $response = $this->client->request('GET', self::FEED, [
          'query' => $query,
          'connect_timeout' => 10,
          'timeout' => 60,
          'http_errors' => FALSE,
          'headers' => ['User-Agent' => 'LSU Libraries Website Search', 'Accept' => 'text/xml'],
        ]);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() === 200 && str_contains($body, '<OAI-PMH')) {
          $document = new \DOMDocument();
          $previous = libxml_use_internal_errors(TRUE);
          $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_COMPACT);
          libxml_clear_errors();
          libxml_use_internal_errors($previous);
          if ($loaded) {
            $xpath = new \DOMXPath($document);
            foreach (self::NS as $prefix => $uri) {
              $xpath->registerNamespace($prefix, $uri);
            }
            return $xpath;
          }
        }
        $problem = 'HTTP ' . $response->getStatusCode();
      }
      catch (GuzzleException $e) {
        $problem = $e->getMessage();
      }
      if ($try < self::TRIES) {
        sleep(5 * $try);
      }
    }
    $this->loggerFactory->get('palefire_search')->error('Repository harvest: the feed couldn’t be read (@problem).', ['@problem' => $problem]);
    return NULL;
  }

}

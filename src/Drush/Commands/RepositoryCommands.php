<?php

namespace Drupal\palefire_search\Drush\Commands;

use Drupal\palefire_search\RepositoryHarvest;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Harvests the Scholarly Repository's records for the Index.
 */
final class RepositoryCommands extends DrushCommands {

  public function __construct(
    private readonly RepositoryHarvest $harvest,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('palefire_search.repository_harvest'));
  }

  /**
   * Copies the Scholarly Repository's records into the site over OAI-PMH.
   */
  #[CLI\Command(name: 'palefire-search:repository-harvest', aliases: ['pfs-repo'])]
  #[CLI\Option(name: 'full', description: 'Read every record again and drop any the repository no longer lists.')]
  #[CLI\Option(name: 'pages', description: 'Stop after this many pages of 100 records (0: no limit). A later run carries on.')]
  #[CLI\Option(name: 'status', description: 'Only say how many records are held and when the last run finished.')]
  #[CLI\Usage(name: 'drush palefire-search:repository-harvest', description: 'The first time, every record (about 30 to 45 minutes); after that, what changed.')]
  #[CLI\Usage(name: 'drush palefire-search:repository-harvest --full', description: 'Every record again.')]
  public function harvest(array $options = ['full' => FALSE, 'pages' => 0, 'status' => FALSE]): int {
    if ($options['status']) {
      $this->output()->writeln($this->summary());
      return self::EXIT_SUCCESS;
    }
    $report = function (int $read, ?int $size): void {
      $this->logger()->notice($size ? sprintf('%s of %s records read', number_format($read), number_format($size)) : sprintf('%s records read', number_format($read)));
    };
    $result = $this->harvest->harvest((int) $options['pages'], (bool) $options['full'], $report);
    if ($result['locked']) {
      $this->logger()->warning('Another harvest is running (or one stopped in the last 10 minutes). Try again shortly.');
      return self::EXIT_FAILURE;
    }
    if ($result['failed']) {
      $this->logger()->error('The repository’s feed couldn’t be read. Run the command again to carry on from the last page; drush watchdog:show --type=palefire_search has details.');
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($result['done']
      ? sprintf('Done: %s records saved. %s', number_format($result['records']), $this->summary())
      : sprintf('Stopped after %d pages (%s records). Run it again to carry on.', $result['pages'], number_format($result['records'])));
    return self::EXIT_SUCCESS;
  }

  /**
   * Records held and the last finished run, in a line.
   */
  private function summary(): string {
    $status = $this->harvest->status();
    $finished = $status['last']['finished'] ?? NULL;
    return sprintf('%s records held. Last finished run: %s.%s',
      number_format($status['records']),
      $finished ? date('Y-m-d H:i', $finished) : 'none',
      $status['run'] ? ' A run is under way and will carry on.' : '');
  }

}

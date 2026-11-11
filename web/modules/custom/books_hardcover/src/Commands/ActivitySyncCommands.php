<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\books_hardcover\Services\ActivitySyncService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command syncing reading activities with Hardcover.
 */
class ActivitySyncCommands extends DrushCommands {

  /**
   * Longest rate-limit delay, in seconds, the command will sleep through.
   */
  protected const MAX_SLEEP = 300;

  /**
   * Human labels for Hardcover status ids.
   */
  protected const HC_STATUSES = [
    1 => 'Want to Read',
    2 => 'Currently Reading',
    3 => 'Read',
    4 => 'Paused',
    5 => 'Did Not Finish',
    6 => 'Ignored',
  ];

  /**
   * Constructs an ActivitySyncCommands object.
   *
   * @param \Drupal\books_hardcover\Services\ActivitySyncService $activitySync
   *   The activity sync.
   */
  public function __construct(
    private ActivitySyncService $activitySync,
  ) {
    parent::__construct();
  }

  /**
   * Sync reading activities with the Hardcover reading log.
   *
   * Fills gaps in both directions and never overwrites or deletes: readings
   * missing on one side are created there, missing dates are copied across,
   * and dates that disagree are listed for you to fix by hand.
   *
   * @param array $options
   *   Command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   One row per change.
   *
   * @phpstan-param array{dry-run?: bool, format?: string} $options
   */
  #[CLI\Command(name: 'books:hardcover-activities', aliases: ['bha'])]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without changing anything.')]
  #[CLI\FieldLabels(labels: ['action' => 'Action', 'book' => 'Book', 'details' => 'Details', 'result' => 'Result'])]
  #[CLI\Usage(name: 'books:hardcover-activities --dry-run', description: 'Preview the sync.')]
  #[CLI\Usage(name: 'books:hardcover-activities', description: 'Run the sync.')]
  public function sync(array $options = ['dry-run' => FALSE, 'format' => 'table']): RowsOfFields {
    $report = $this->activitySync->sync(
      (bool) $options['dry-run'],
      function (int $delay): bool {
        if ($delay > static::MAX_SLEEP) {
          return FALSE;
        }
        $this->logger()->notice(dt('Rate limited, waiting @seconds s.', ['@seconds' => $delay]));
        sleep($delay);
        return TRUE;
      },
    );

    $rows = array_map(fn(array $op): array => [
      'action' => $this->describeAction($op),
      'book' => (string) ($op['label'] ?? ''),
      'details' => $this->describeDetails($op),
      'result' => $op['result'],
    ], $report['ops']);

    $counts = array_count_values(array_column($report['ops'], 'result'));
    if ($report['ops'] === []) {
      $this->logger()->success(dt('Already in sync.'));
    }
    elseif ($options['dry-run']) {
      $this->logger()->success(dt('Dry run: @planned change(s) planned, @reported to review. Nothing was changed.', [
        '@planned' => $counts['planned'] ?? 0,
        '@reported' => $counts['reported'] ?? 0,
      ]));
    }
    else {
      $this->logger()->success(dt('@done done, @failed failed, @pending pending, @reported to review.', [
        '@done' => $counts['done'] ?? 0,
        '@failed' => $counts['failed'] ?? 0,
        '@pending' => $counts['pending'] ?? 0,
        '@reported' => $counts['reported'] ?? 0,
      ]));
    }
    if ($report['stopped'] !== NULL) {
      $this->logger()->warning($report['stopped']);
    }

    return new RowsOfFields($rows);
  }

  /**
   * Describes an operation's kind.
   *
   * @param array<string, mixed> $op
   *   The operation.
   *
   * @return string
   *   A short label.
   */
  protected function describeAction(array $op): string {
    return match ($op['op']) {
      'pull' => $op['book_nid'] === NULL ? 'Site: add book + activity' : 'Site: add activity',
      'update_local' => 'Site: fill activity',
      'push' => $op['user_book_id'] === NULL ? 'Hardcover: add book + read' : 'Hardcover: add read',
      'update_remote' => 'Hardcover: fill read',
      'set_status' => 'Hardcover: set status',
      'conflict' => 'Review: dates differ',
      'skip' => 'Review: skipped',
      default => $op['op'],
    };
  }

  /**
   * Describes an operation's details.
   *
   * @param array<string, mixed> $op
   *   The operation.
   *
   * @return string
   *   A short description.
   */
  protected function describeDetails(array $op): string {
    $range = static fn(?string $start, ?string $end): string => ($start ?? '?') . ' → ' . ($end ?? '…');

    return match ($op['op']) {
      'pull', 'push' => $range($op['start'], $op['end']) . ($op['op'] === 'pull' ? ' (' . $op['status'] . ')' : ''),
      'update_local' => implode(', ', array_map(
        static fn(string $k, string $v): string => "$k = $v",
        array_keys($op['values']),
        $op['values'],
      )),
      'update_remote' => implode(', ', array_map(
        static fn(string $k, string $v): string => "$k = $v",
        array_keys($op['dates']),
        $op['dates'],
      )),
      'set_status' => (self::HC_STATUSES[$op['from_status_id']] ?? '?') . ' → ' . (self::HC_STATUSES[$op['status_id']] ?? '?'),
      'conflict' => sprintf('%s: site %s, Hardcover %s', $op['field'], $op['local'], $op['remote']),
      'skip' => (string) $op['reason'],
      default => '',
    };
  }

}

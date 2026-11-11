<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Hook;

use Drupal\books_hardcover\Services\ActivitySyncService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the Hardcover activity sync from cron.
 *
 * Off unless $settings['hardcover_activity_sync'] is TRUE, so a fresh
 * environment (or a copy of production) never writes to the Hardcover account
 * on its own.
 */
class ActivitySyncHooks {

  /**
   * Seconds between two cron syncs.
   */
  public const INTERVAL = 3600;

  /**
   * State key holding the time of the last cron sync.
   */
  public const STATE_KEY = 'books_hardcover.activity_sync_last';

  /**
   * Constructs an ActivitySyncHooks object.
   *
   * @param \Drupal\books_hardcover\Services\ActivitySyncService $activitySync
   *   The activity sync.
   * @param \Drupal\Core\Site\Settings $settings
   *   Site settings.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    #[Autowire(service: 'books_hardcover.activity_sync')]
    protected ActivitySyncService $activitySync,
    protected Settings $settings,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!$this->settings->get('hardcover_activity_sync', FALSE)) {
      return;
    }

    $now = $this->time->getRequestTime();
    if ($now - (int) $this->state->get(self::STATE_KEY, 0) < self::INTERVAL) {
      return;
    }
    // Recorded before running, so a failing sync is retried an hour later
    // rather than on every cron run.
    $this->state->set(self::STATE_KEY, $now);

    $logger = $this->loggerChannelFactory->get('HardcoverActivitySync');
    try {
      $report = $this->activitySync->sync();
    }
    catch (\Exception $e) {
      $logger->error('Activity sync did not run: @message', ['@message' => $e->getMessage()]);
      return;
    }

    $counts = array_count_values(array_column($report['ops'], 'result'));
    $logger->info('Activity sync: @done done, @failed failed, @pending pending, @reported to review.', [
      '@done' => $counts['done'] ?? 0,
      '@failed' => $counts['failed'] ?? 0,
      '@pending' => $counts['pending'] ?? 0,
      '@reported' => $counts['reported'] ?? 0,
    ]);
    if ($report['stopped'] !== NULL) {
      $logger->notice($report['stopped']);
    }
  }

}

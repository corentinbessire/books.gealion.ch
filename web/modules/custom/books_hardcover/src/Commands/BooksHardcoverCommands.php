<?php

namespace Drupal\books_hardcover\Commands;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_hardcover\Services\BookSyncService;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class BooksHardcoverCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * How often a single item may fail before the drain gives up on the queue.
   *
   * DatabaseQueue::releaseItem() sets expire to 0 and claimItem() picks the
   * oldest claimable row, so a released item is handed straight back. Without
   * a cap a permanently failing item would spin forever.
   */
  protected const MAX_ITEM_ATTEMPTS = 3;

  /**
   * Longest rate-limit delay, in seconds, this command will sleep through.
   *
   * Hardcover's daily bucket resets up to 24 hours out; blocking a terminal
   * for that long is never the right answer, so the queue is left to cron.
   */
  protected const MAX_SLEEP = 300;

  /**
   * Wall-clock budget for one drain, in seconds.
   *
   * Core's Cron::processQueue() bounds queue processing the same way.
   */
  protected const DRAIN_TIME_LIMIT = 3600;

  /**
   * BooksHardcoverCommands constructor.
   *
   * @param \Drupal\books_hardcover\Services\BookSyncService $bookSync
   *   Enqueues books for a Hardcover sync.
   * @param \Drupal\books_catalog\Services\BookService $bookService
   *   Reads book nodes.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorkerManager
   *   Queue worker plugin manager.
   */
  public function __construct(
    private BookSyncService $bookSync,
    private BookService $bookService,
    private QueueFactory $queueFactory,
    private QueueWorkerManagerInterface $queueWorkerManager,
  ) {
    parent::__construct();
  }

  /**
   * Queue books for a Hardcover sync.
   *
   * @param array $options
   *   Command options.
   *
   * @option nid
   *   Sync only this node ID.
   * @option run
   *   Drain the queue immediately instead of waiting for cron.
   *
   * @usage books:sync
   *   Queue every book and let cron drain the queue.
   * @usage books:sync --run
   *   Queue every book and process them now.
   *
   * @command books:sync
   * @aliases bs
   *
   * @phpstan-param array{nid?: int|string|null, run?: bool} $options
   */
  public function sync(array $options = ['nid' => NULL, 'run' => FALSE]): void {
    $nids = $options['nid'] ? [$options['nid']] : $this->bookService->getAllBooks();
    $queued = $this->bookSync->queueBooksForSync($nids);

    $this->logger()->success(dt('@count book(s) queued.', ['@count' => $queued]));

    if ($options['run']) {
      $this->drainQueue();
    }
  }

  /**
   * Queue books missing a cover for a Hardcover sync.
   *
   * Covers arrive in the same request as the rest of the data, so this is a
   * normal gap-filling sync restricted to books without a cover.
   *
   * @usage update-cover
   *   Queue every book missing a cover.
   *
   * @command update-cover
   * @aliases buc
   */
  public function updateCover(): void {
    $queued = $this->bookSync->queueBooksForSync(
      $this->bookService->getBooksMissingCover()
    );

    if ($queued === 0) {
      $this->logger()->warning(dt('No books without cover.'));
      return;
    }

    $this->logger()->success(dt('@count book(s) queued for cover sync.', ['@count' => $queued]));
    $this->drainQueue();
  }

  /**
   * Processes the sync queue in-process, honouring the API rate limit.
   *
   * Cron uses DelayedRequeueException to postpone throttled items; running
   * in-process there is nothing to postpone to, so short delays are slept
   * through. Anything the drain cannot handle — a long daily throttle, a
   * repeatedly failing item, an exhausted time budget — leaves the item in the
   * queue for cron rather than dropping it.
   */
  protected function drainQueue(): void {
    $queue = $this->queueFactory->get('hardcover_book_sync');
    $worker = $this->queueWorkerManager->createInstance('hardcover_book_sync');
    $processed = 0;
    $failed = 0;
    $attempts = [];
    $deadline = time() + static::DRAIN_TIME_LIMIT;

    while ($item = $queue->claimItem()) {
      if (!is_object($item)) {
        break;
      }

      if (time() >= $deadline) {
        $queue->releaseItem($item);
        $this->logger()->warning(dt('Time budget of @seconds s used up; @count item(s) stay in the queue for cron.', [
          '@seconds' => static::DRAIN_TIME_LIMIT,
          '@count' => $queue->numberOfItems(),
        ]));
        break;
      }

      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        unset($attempts[$item->item_id]);
        $processed++;
      }
      catch (DelayedRequeueException $e) {
        $queue->releaseItem($item);
        $delay = (int) $e->getDelay();

        if ($delay > static::MAX_SLEEP) {
          $this->logger()->warning(dt('Hardcover daily limit exhausted; it resets at @time. @count item(s) stay in the queue for cron.', [
            '@time' => date('Y-m-d H:i:s', time() + $delay),
            '@count' => $queue->numberOfItems(),
          ]));
          break;
        }

        $this->logger()->notice(dt('Rate limited, waiting @seconds s.', [
          '@seconds' => $delay,
        ]));
        sleep($delay);
      }
      catch (RequeueException $e) {
        $failed++;
        if ($this->releaseFailedItem($queue, $item, $attempts, $e->getMessage())) {
          break;
        }
      }
      catch (\Exception $e) {
        // Core's Cron::processQueue() logs and leaves the item alone; deleting
        // it here would throw away a scanned book because saveBookData() or
        // downloadBookCover() happened to fail.
        $failed++;
        $this->logger()->error($e->getMessage());
        if ($this->releaseFailedItem($queue, $item, $attempts, $e->getMessage())) {
          break;
        }
      }
    }

    $this->logger()->success(dt('@processed synced, @failed failed.', [
      '@processed' => $processed,
      '@failed' => $failed,
    ]));
  }

  /**
   * Releases a failed item and reports whether the drain should stop.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue the item came from.
   * @param object $item
   *   The claimed queue item.
   * @param array<int|string, int> $attempts
   *   Attempt counts keyed by item id, updated in place.
   * @param string $message
   *   The failure message, for the log.
   *
   * @return bool
   *   TRUE when the item has failed too often and the drain must stop.
   */
  protected function releaseFailedItem(QueueInterface $queue, object $item, array &$attempts, string $message): bool {
    $queue->releaseItem($item);
    $attempts[$item->item_id] = ($attempts[$item->item_id] ?? 0) + 1;

    if ($attempts[$item->item_id] < static::MAX_ITEM_ATTEMPTS) {
      return FALSE;
    }

    $this->logger()->error(dt('Queue item @id failed @attempts times (@message); stopping so it is not retried in a tight loop. It stays in the queue for cron.', [
      '@id' => $item->item_id,
      '@attempts' => $attempts[$item->item_id],
      '@message' => $message,
    ]));

    return TRUE;
  }

}

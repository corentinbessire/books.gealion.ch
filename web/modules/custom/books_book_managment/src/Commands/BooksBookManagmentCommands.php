<?php

namespace Drupal\books_book_managment\Commands;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\books_book_managment\Services\BooksUtilsService;
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
class BooksBookManagmentCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * BooksBookManagmentCommands constructor.
   *
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Utils for Book management service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorkerManager
   *   Queue worker plugin manager.
   */
  public function __construct(
    private BooksUtilsService $booksUtilsService,
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
   */
  public function sync(array $options = ['nid' => NULL, 'run' => FALSE]): void {
    $nids = $options['nid'] ? [$options['nid']] : $this->booksUtilsService->getAllBooks();
    $queued = $this->booksUtilsService->queueBooksForSync($nids);

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
    $queued = $this->booksUtilsService->queueBooksForSync(
      $this->booksUtilsService->getBooksMissingCover()
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
   * in-process there is nothing to postpone to, so this sleeps instead.
   */
  protected function drainQueue(): void {
    $queue = $this->queueFactory->get('hardcover_book_sync');
    $worker = $this->queueWorkerManager->createInstance('hardcover_book_sync');
    $processed = 0;
    $failed = 0;

    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (DelayedRequeueException $e) {
        $queue->releaseItem($item);
        $this->logger()->notice(dt('Rate limited, waiting @seconds s.', [
          '@seconds' => $e->getDelay(),
        ]));
        sleep($e->getDelay());
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $failed++;
      }
      catch (\Exception $e) {
        $queue->deleteItem($item);
        $failed++;
        $this->logger()->error($e->getMessage());
      }
    }

    $this->logger()->success(dt('@processed synced, @failed failed.', [
      '@processed' => $processed,
      '@failed' => $failed,
    ]));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\books_hardcover\Unit\Commands;

use Drupal\books_hardcover\Commands\BooksHardcoverCommands;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Tests\UnitTestCase;
use Drush\Log\DrushLoggerManager;

// Drush ships dt() in an include file that Composer does not autoload.
if (!function_exists('dt')) {
  require_once dirname(__DIR__, 8) . '/vendor/drush/drush/includes/output.inc';
}

/**
 * Unit tests for the drush command's queue draining.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Commands\BooksHardcoverCommands
 */
class BooksHardcoverCommandsTest extends UnitTestCase {

  /**
   * The queue mock.
   *
   * @var \Drupal\Core\Queue\QueueInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queue;

  /**
   * The queue worker mock.
   *
   * @var \Drupal\Core\Queue\QueueWorkerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $worker;

  /**
   * The command under test.
   *
   * @var \Drupal\books_hardcover\Commands\BooksHardcoverCommands
   */
  protected $command;

  /**
   * The mocked sync service.
   *
   * @var \Drupal\books_hardcover\Services\BookSyncService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $bookSync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->queue = $this->createMock(QueueInterface::class);
    $this->queue->method('numberOfItems')->willReturn(1);

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with('hardcover_book_sync')->willReturn($this->queue);

    $this->worker = $this->createMock(QueueWorkerInterface::class);
    $workerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $workerManager->method('createInstance')->willReturn($this->worker);

    $this->command = new BooksHardcoverCommands(
      $this->createMock(BookSyncService::class),
      $this->createMock(BookService::class),
      $queueFactory,
      $workerManager
    );
    $this->command->setLogger($this->createMock(DrushLoggerManager::class));
  }

  /**
   * Runs the protected drainQueue() method.
   */
  protected function drainQueue(): void {
    $method = new \ReflectionMethod($this->command, 'drainQueue');
    $method->invoke($this->command);
  }

  /**
   * Builds a claimable queue item.
   *
   * @param int $itemId
   *   The item id.
   *
   * @return object
   *   A queue item as DatabaseQueue::claimItem() returns it.
   */
  protected function item(int $itemId = 1): object {
    return (object) [
      'item_id' => $itemId,
      'data' => ['nid' => 42, 'isbn' => '9780765326379'],
    ];
  }

  /**
   * Tests that a successful item is deleted from the queue.
   *
   * @covers ::drainQueue
   */
  public function testSuccessfulItemIsDeleted(): void {
    $this->queue->method('claimItem')->willReturnOnConsecutiveCalls($this->item(), FALSE);
    $this->queue->expects($this->once())->method('deleteItem');
    $this->queue->expects($this->never())->method('releaseItem');

    $this->drainQueue();
  }

  /**
   * Tests that a repeatedly requeued item does not spin forever.
   *
   * A released item becomes immediately claimable again, so without an
   * attempt cap the same failing item would be retried in a tight loop.
   *
   * @covers ::drainQueue
   * @covers ::releaseFailedItem
   */
  public function testRequeueExceptionStopsAfterAttemptCap(): void {
    $this->queue->method('claimItem')->willReturn($this->item());
    $this->worker->method('processItem')
      ->willThrowException(new RequeueException('connection refused'));

    $this->queue->expects($this->exactly(3))->method('releaseItem');
    $this->queue->expects($this->never())->method('deleteItem');

    $this->drainQueue();
  }

  /**
   * Tests that an unexpected exception releases the item instead of dropping.
   *
   * Deleting here would throw away a scanned book because saving the node or
   * downloading its cover failed, which the queue exists to prevent.
   *
   * @covers ::drainQueue
   * @covers ::releaseFailedItem
   */
  public function testUnexpectedExceptionReleasesItem(): void {
    $this->queue->method('claimItem')->willReturn($this->item());
    $this->worker->method('processItem')
      ->willThrowException(new \RuntimeException('entity storage exploded'));

    $this->queue->expects($this->exactly(3))->method('releaseItem');
    $this->queue->expects($this->never())->method('deleteItem');

    $this->drainQueue();
  }

  /**
   * Tests that a short rate-limit delay is slept through and retried.
   *
   * @covers ::drainQueue
   */
  public function testShortDelayIsSleptThrough(): void {
    $this->queue->method('claimItem')->willReturnOnConsecutiveCalls($this->item(), FALSE);
    $this->worker->method('processItem')
      ->willThrowException(new DelayedRequeueException(1, 'throttled'));

    $this->queue->expects($this->once())->method('releaseItem');
    $this->queue->expects($this->never())->method('deleteItem');

    $this->drainQueue();
  }

  /**
   * Tests that a daily-limit delay ends the drain instead of blocking.
   *
   * The daily bucket can reset a full day out; sleeping on it would freeze the
   * terminal for hours.
   *
   * @covers ::drainQueue
   */
  public function testDailyLimitEndsDrainWithoutSleeping(): void {
    $this->queue->method('claimItem')->willReturn($this->item());
    $this->worker->method('processItem')
      ->willThrowException(new DelayedRequeueException(86400, 'daily limit'));

    $this->queue->expects($this->once())->method('releaseItem');
    $this->queue->expects($this->never())->method('deleteItem');

    $started = time();
    $this->drainQueue();

    $this->assertLessThan(5, time() - $started, 'The drain returned instead of sleeping out the daily limit.');
  }

}

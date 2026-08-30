<?php

namespace Drupal\Tests\books_hardcover\Kernel\Plugin\QueueWorker;

use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\books_hardcover\Services\HardcoverService;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for the Hardcover book sync queue worker.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Plugin\QueueWorker\HardcoverBookSync
 */
class HardcoverBookSyncKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'taxonomy',
    'field',
    'text',
    'file',
    'image',
    'media',
    'user',
    'isbn',
    'books_catalog',
    'books_cover',
    'books_hardcover',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
  }

  /**
   * Returns the worker with a stubbed Hardcover service.
   *
   * @param \Drupal\books_hardcover\Services\HardcoverService $hardcover
   *   The stubbed service.
   *
   * @return \Drupal\Core\Queue\QueueWorkerInterface
   *   The worker plugin.
   */
  protected function workerWith(HardcoverService $hardcover) {
    $this->container->set('books_hardcover.client', $hardcover);
    return $this->container->get('plugin.manager.queue_worker')
      ->createInstance('hardcover_book_sync');
  }

  /**
   * Tests that a rate limit becomes a delayed requeue, never a lost item.
   *
   * @covers ::processItem
   */
  public function testRateLimitCausesDelayedRequeue(): void {
    $hardcover = $this->createMock(HardcoverService::class);
    $hardcover->method('getFormattedBookData')
      ->willThrowException(new HardcoverRateLimitException(30));

    $worker = $this->workerWith($hardcover);

    $this->expectException(DelayedRequeueException::class);
    try {
      $worker->processItem(['nid' => 1, 'isbn' => '9780765326379', 'only_fill_gaps' => TRUE]);
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(30, $e->getDelay());
      throw $e;
    }
  }

  /**
   * Tests that a book Hardcover does not know consumes its item.
   *
   * Requeueing it forever would burn quota on a book that will never resolve.
   *
   * @covers ::processItem
   */
  public function testUnknownIsbnConsumesItem(): void {
    $hardcover = $this->createMock(HardcoverService::class);
    $hardcover->method('getFormattedBookData')->willReturn(NULL);

    $worker = $this->workerWith($hardcover);

    $worker->processItem(['nid' => 1, 'isbn' => '9780000000000', 'only_fill_gaps' => TRUE]);
    // No exception means the item was consumed rather than requeued.
    $this->addToAssertionCount(1);
  }

}

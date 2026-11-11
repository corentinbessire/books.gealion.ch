<?php

declare(strict_types=1);

namespace Drupal\Tests\books_hardcover\Unit\Services;

use Drupal\books_activity\Services\ActivityStatusService;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_hardcover\Services\ActivitySyncService;
use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\books_hardcover\Services\HardcoverService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the Hardcover writes of the activity sync.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Services\ActivitySyncService
 */
class ActivitySyncServiceTest extends UnitTestCase {

  /**
   * The mocked Hardcover client.
   */
  protected HardcoverService&MockObject $hardcover;

  /**
   * Builds the service under test, exposing its protected steps.
   */
  protected function buildService(): TestableActivitySyncService {
    $this->hardcover = $this->createMock(HardcoverService::class);
    return new TestableActivitySyncService(
      $this->hardcover,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ActivityStatusService::class),
      $this->createMock(BookService::class),
      $this->createMock(BookSyncService::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
    );
  }

  /**
   * A read created by adding the book is reused rather than duplicated.
   *
   * @covers ::push
   */
  public function testPushReusesReadCreatedWithLibraryEntry(): void {
    $service = $this->buildService();

    $this->hardcover->expects($this->once())->method('insertUserBook')
      ->with(['book_id' => 1, 'edition_id' => 101, 'status_id' => 3, 'imported' => TRUE])
      ->willReturn([
        'id' => 500,
        'user_book_reads' => [['id' => 9, 'started_at' => NULL, 'finished_at' => '2026-11-11']],
      ]);
    $this->hardcover->expects($this->never())->method('insertUserBookRead');
    $this->hardcover->expects($this->once())->method('updateUserBookRead')
      ->with(9, ['started_at' => '2022-01-24', 'finished_at' => '2022-01-29', 'edition_id' => 101])
      ->willReturn(['id' => 9, 'started_at' => '2022-01-24', 'finished_at' => '2022-01-29']);

    $this->assertTrue($service->callPush([
      'book_id' => 1,
      'edition_id' => 101,
      'user_book_id' => NULL,
      'start' => '2022-01-24',
      'end' => '2022-01-29',
      'status_id' => 3,
    ]));
  }

  /**
   * A second reading of a book added this run goes onto the same entry.
   *
   * @covers ::push
   */
  public function testSecondPushAddsReadToCreatedEntry(): void {
    $service = $this->buildService();

    $this->hardcover->expects($this->once())->method('insertUserBook')
      ->willReturn(['id' => 500, 'user_book_reads' => []]);
    $this->hardcover->expects($this->exactly(2))->method('insertUserBookRead')
      ->with(500, $this->anything())
      ->willReturnOnConsecutiveCalls(
        ['id' => 9, 'started_at' => '2020-01-01', 'finished_at' => '2020-02-01'],
        ['id' => 10, 'started_at' => '2026-11-01', 'finished_at' => NULL],
      );

    $op = ['book_id' => 1, 'edition_id' => NULL, 'user_book_id' => NULL, 'status_id' => 2];
    $this->assertTrue($service->callPush($op + ['start' => '2020-01-01', 'end' => '2020-02-01']));
    $this->assertTrue($service->callPush($op + ['start' => '2026-11-01', 'end' => NULL]));
  }

  /**
   * A status change's own reads are deleted and altered dates restored.
   *
   * @covers ::setStatus
   */
  public function testSetStatusUndoesSideEffects(): void {
    $service = $this->buildService();
    $service->seedExpected(500, [
      9 => ['started_at' => '2026-11-01', 'finished_at' => NULL],
    ]);

    $this->hardcover->method('updateUserBook')
      ->with(500, ['status_id' => 2])
      ->willReturn(['id' => 500, 'user_book_reads' => [
        // Ours, but the status change closed it.
        ['id' => 9, 'started_at' => '2026-11-01', 'finished_at' => '2026-11-11'],
        // Started by the status change itself.
        ['id' => 11, 'started_at' => '2026-11-11', 'finished_at' => NULL],
      ]]);
    $this->hardcover->expects($this->once())->method('deleteUserBookRead')->with(11);
    $this->hardcover->expects($this->once())->method('updateUserBookRead')
      ->with(9, ['finished_at' => NULL]);

    $this->assertTrue($service->callSetStatus(['user_book_id' => 500, 'status_id' => 2]));
  }

  /**
   * A status change without side effects writes nothing more.
   *
   * @covers ::setStatus
   */
  public function testSetStatusWithoutSideEffects(): void {
    $service = $this->buildService();
    $service->seedExpected(500, [
      9 => ['started_at' => '2024-01-01', 'finished_at' => '2024-02-01'],
    ]);

    $this->hardcover->method('updateUserBook')
      ->willReturn(['id' => 500, 'user_book_reads' => [
        ['id' => 9, 'started_at' => '2024-01-01', 'finished_at' => '2024-02-01'],
      ]]);
    $this->hardcover->expects($this->never())->method('deleteUserBookRead');
    $this->hardcover->expects($this->never())->method('updateUserBookRead');

    $this->assertTrue($service->callSetStatus(['user_book_id' => 500, 'status_id' => 3]));
  }

}

/**
 * Exposes ActivitySyncService's protected steps to the test.
 */
class TestableActivitySyncService extends ActivitySyncService {

  /**
   * Calls push().
   *
   * @param array<string, mixed> $op
   *   The operation.
   */
  public function callPush(array $op): bool {
    return $this->push($op);
  }

  /**
   * Calls setStatus().
   *
   * @param array<string, mixed> $op
   *   The operation.
   */
  public function callSetStatus(array $op): bool {
    return $this->setStatus($op);
  }

  /**
   * Seeds the reads a library entry is known to hold.
   *
   * @param int $userBookId
   *   The entry.
   * @param array<int, array{started_at: string|null, finished_at: string|null}> $reads
   *   Its reads.
   */
  public function seedExpected(int $userBookId, array $reads): void {
    $this->expectedReads[$userBookId] = $reads;
  }

}

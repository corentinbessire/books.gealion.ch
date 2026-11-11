<?php

declare(strict_types=1);

namespace Drupal\Tests\books_hardcover\Unit\Sync;

use Drupal\books_hardcover\Sync\ActivitySyncPlanner;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the activity sync planner.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Sync\ActivitySyncPlanner
 */
class ActivitySyncPlannerTest extends UnitTestCase {

  /**
   * Editions known to Hardcover, keyed by ISBN-13.
   */
  protected const EDITIONS = [
    '9780000000001' => ['edition_id' => 101, 'book_id' => 1],
    '9780000000002' => ['edition_id' => 102, 'book_id' => 2],
  ];

  /**
   * Builds a site activity.
   *
   * @param int $nid
   *   The node id.
   * @param string $status
   *   The status name.
   * @param string|null $start
   *   The start date.
   * @param string|null $end
   *   The end date.
   * @param string $isbn
   *   The book's ISBN-13.
   *
   * @return array<string, mixed>
   *   The activity.
   */
  protected function activity(int $nid, string $status, ?string $start, ?string $end, string $isbn = '9780000000001'): array {
    return [
      'nid' => $nid,
      'label' => "Book $nid",
      'isbn' => $isbn,
      'status' => $status,
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Builds a Hardcover library entry.
   *
   * @param int $statusId
   *   The Hardcover status id.
   * @param array<int, array<string, mixed>> $reads
   *   Its reads.
   * @param int $bookId
   *   The Hardcover book id.
   * @param string|null $isbn
   *   The ISBN-13 of its edition.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  protected function userBook(int $statusId, array $reads, int $bookId = 1, ?string $isbn = '9780000000001'): array {
    return [
      'id' => 500 + $bookId,
      'book_id' => $bookId,
      'edition_id' => 100 + $bookId,
      'status_id' => $statusId,
      'title' => "Remote $bookId",
      'isbn' => $isbn,
      'reads' => $reads,
    ];
  }

  /**
   * Builds a Hardcover read.
   *
   * @param int $id
   *   The read id.
   * @param string|null $start
   *   The start date.
   * @param string|null $end
   *   The end date.
   * @param string|null $isbn
   *   The ISBN-13 of the edition read.
   *
   * @return array<string, mixed>
   *   The read.
   */
  protected function read(int $id, ?string $start, ?string $end, ?string $isbn = NULL): array {
    return ['id' => $id, 'start' => $start, 'end' => $end, 'isbn' => $isbn];
  }

  /**
   * Plans and returns the operations with the given op name.
   *
   * @param array<int, array<string, mixed>> $ops
   *   All operations.
   * @param string $op
   *   The op name.
   *
   * @return array<int, array<string, mixed>>
   *   Matching operations.
   */
  protected function ops(array $ops, string $op): array {
    return array_values(array_filter($ops, static fn(array $o): bool => $o['op'] === $op));
  }

  /**
   * Readings already identical on both sides produce no operation.
   *
   * @covers ::plan
   */
  public function testInSyncDoesNothing(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [$this->activity(1, 'Finished', '2024-01-01', '2024-02-01')],
      [$this->userBook(3, [$this->read(9, '2024-01-01', '2024-02-01')])],
      self::EDITIONS,
      ['9780000000001' => 1],
    );
    $this->assertSame([], $ops);
  }

  /**
   * A Hardcover read without a start date gets the site's start date.
   *
   * @covers ::plan
   */
  public function testFillsMissingRemoteStart(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [$this->activity(1, 'Finished', '2024-01-01', '2024-02-01')],
      [$this->userBook(3, [$this->read(9, NULL, '2024-02-01')])],
      self::EDITIONS,
      [],
    );
    $this->assertCount(1, $ops);
    $this->assertSame('update_remote', $ops[0]['op']);
    $this->assertSame(9, $ops[0]['read_id']);
    $this->assertSame(['started_at' => '2024-01-01'], $ops[0]['dates']);
  }

  /**
   * A site reading Hardcover lacks is pushed, creating the library entry.
   *
   * @covers ::plan
   */
  public function testPushesMissingReading(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [$this->activity(1, 'Finished', '2022-01-01', '2022-01-24')],
      [],
      self::EDITIONS,
      [],
    );
    $this->assertCount(1, $ops);
    $this->assertSame('push', $ops[0]['op']);
    $this->assertNull($ops[0]['user_book_id']);
    $this->assertSame(1, $ops[0]['book_id']);
    $this->assertSame(101, $ops[0]['edition_id']);
    $this->assertSame(ActivitySyncPlanner::HC_READ, $ops[0]['status_id']);
  }

  /**
   * A Hardcover read the site lacks is pulled, onto the existing book.
   *
   * @covers ::plan
   */
  public function testPullsReadOntoExistingBook(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [],
      [$this->userBook(3, [$this->read(9, '2026-08-23', '2026-08-30', '9780000000001')])],
      self::EDITIONS,
      ['9780000000001' => 42],
    );
    $this->assertCount(1, $ops);
    $this->assertSame('pull', $ops[0]['op']);
    $this->assertSame(42, $ops[0]['book_nid']);
    $this->assertSame('Finished', $ops[0]['status']);
    $this->assertSame('2026-08-23', $ops[0]['start']);
  }

  /**
   * A pulled read whose book is not on the site carries its ISBN.
   *
   * @covers ::plan
   */
  public function testPullsReadForNewBook(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [],
      [$this->userBook(2, [$this->read(9, '2026-11-01', NULL)], 7, '9780000000077')],
      self::EDITIONS,
      [],
    );
    $this->assertSame('pull', $ops[0]['op']);
    $this->assertNull($ops[0]['book_nid']);
    $this->assertSame('9780000000077', $ops[0]['isbn']);
    $this->assertSame('Reading', $ops[0]['status']);
  }

  /**
   * A pulled read with no ISBN anywhere cannot create its book.
   *
   * @covers ::plan
   */
  public function testSkipsPullWithoutIsbn(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [],
      [$this->userBook(3, [$this->read(9, '2026-07-07', '2026-08-27')], 7, NULL)],
      self::EDITIONS,
      [],
    );
    $this->assertSame('skip', $ops[0]['op']);
    $this->assertSame(9, $ops[0]['read_id']);
  }

  /**
   * A site activity still open but finished on Hardcover is closed.
   *
   * @covers ::plan
   */
  public function testClosesLocalReadingFinishedOnHardcover(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [$this->activity(1, 'Reading', '2026-06-22', NULL)],
      [$this->userBook(3, [$this->read(9, NULL, '2026-07-02')])],
      self::EDITIONS,
      [],
    );
    $local = $this->ops($ops, 'update_local');
    $this->assertSame(['end' => '2026-07-02', 'status' => 'Finished'], $local[0]['values']);
    $remote = $this->ops($ops, 'update_remote');
    $this->assertSame(['started_at' => '2026-06-22'], $remote[0]['dates']);
    // Hardcover already says Read; the filled-in site reading agrees.
    $this->assertSame([], $this->ops($ops, 'set_status'));
  }

  /**
   * The last reading abandoned on Hardcover is abandoned on the site.
   *
   * @covers ::plan
   */
  public function testDidNotFinishMapsToAbandoned(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [],
      [$this->userBook(5, [$this->read(9, '2025-01-01', '2025-02-01')])],
      self::EDITIONS,
      ['9780000000001' => 42],
    );
    $this->assertSame('Abandoned', $ops[0]['status']);
  }

  /**
   * Dates set on both sides are never overwritten, only reported.
   *
   * @covers ::plan
   */
  public function testDifferingDatesAreConflicts(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [$this->activity(1, 'Finished', '2024-11-10', '2024-12-08')],
      [$this->userBook(3, [$this->read(9, NULL, '2024-12-30')])],
      self::EDITIONS,
      [],
    );
    $conflict = $this->ops($ops, 'conflict');
    $this->assertCount(1, $conflict);
    $this->assertSame('end', $conflict[0]['field']);
    $this->assertSame('2024-12-08', $conflict[0]['local']);
    $this->assertSame('2024-12-30', $conflict[0]['remote']);
    // The missing start is still filled; the end is left alone.
    $this->assertSame(['started_at' => '2024-11-10'], $this->ops($ops, 'update_remote')[0]['dates']);
    $this->assertSame([], $this->ops($ops, 'update_local'));
  }

  /**
   * A re-read started on the site is pushed and the status follows it.
   *
   * @covers ::plan
   */
  public function testRereadIsPushedAndStatusFollows(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [
        $this->activity(1, 'Finished', '2020-01-01', '2020-02-01'),
        $this->activity(2, 'Reading', '2026-11-01', NULL),
      ],
      [$this->userBook(3, [$this->read(9, NULL, '2020-02-01')])],
      self::EDITIONS,
      [],
    );
    $push = $this->ops($ops, 'push');
    $this->assertCount(1, $push);
    $this->assertSame(2, $push[0]['nid']);
    $this->assertSame(501, $push[0]['user_book_id']);
    $status = $this->ops($ops, 'set_status');
    $this->assertSame(ActivitySyncPlanner::HC_READING, $status[0]['status_id']);
    // Status changes come after the reads they depend on.
    $this->assertSame('set_status', end($ops)['op']);
  }

  /**
   * Several unmatched readings on both sides are left alone.
   *
   * @covers ::plan
   */
  public function testAmbiguousReadingsAreSkipped(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [
        $this->activity(1, 'Finished', '2020-01-01', '2020-02-01'),
        $this->activity(2, 'Finished', '2021-01-01', '2021-02-01'),
      ],
      [$this->userBook(3, [$this->read(8, NULL, '2020-03-01'), $this->read(9, NULL, '2021-03-01')])],
      self::EDITIONS,
      [],
    );
    $this->assertSame([], $this->ops($ops, 'push'));
    $this->assertSame([], $this->ops($ops, 'pull'));
    $this->assertCount(1, $this->ops($ops, 'skip'));
  }

  /**
   * Another edition of a library book is matched by title, not duplicated.
   *
   * @covers ::plan
   * @covers ::normaliseTitle
   */
  public function testUnknownIsbnFallsBackToTitle(): void {
    $activity = ['book_nid' => 42, 'label' => 'Fated Sky, The'] + $this->activity(1, 'Finished', '2021-09-01', '2021-10-13', '9789999999999');
    $remote = ['title' => 'The Fated Sky'] + $this->userBook(3, [$this->read(9, NULL, '2021-10-13', '9780000000077')], 7, '9780000000077');

    $ops = (new ActivitySyncPlanner())->plan([$activity], [$remote], self::EDITIONS, ['9789999999999' => 42]);

    $this->assertSame([], $this->ops($ops, 'pull'));
    $this->assertSame([], $this->ops($ops, 'push'));
    $this->assertSame(['started_at' => '2021-09-01'], $this->ops($ops, 'update_remote')[0]['dates']);
  }

  /**
   * Titles are compared without articles, case or punctuation.
   *
   * @covers ::normaliseTitle
   */
  public function testNormaliseTitle(): void {
    $this->assertSame(ActivitySyncPlanner::normaliseTitle('The Fated Sky'), ActivitySyncPlanner::normaliseTitle('Fated Sky, The'));
    $this->assertSame(ActivitySyncPlanner::normaliseTitle('Martian Contingency'), ActivitySyncPlanner::normaliseTitle('The Martian Contingency'));
    $this->assertNotSame(ActivitySyncPlanner::normaliseTitle('Golden Son'), ActivitySyncPlanner::normaliseTitle('Red Rising'));
  }

  /**
   * Books Hardcover does not know, To Read activities and ignored books.
   *
   * @covers ::plan
   */
  public function testIgnoredInputs(): void {
    $ops = (new ActivitySyncPlanner())->plan(
      [
        $this->activity(1, 'Finished', '2020-01-01', '2020-02-01', '9789999999999'),
        $this->activity(2, 'To Read', NULL, NULL),
        $this->activity(3, 'Finished', '2020-01-01', '2020-02-01', '9780000000002'),
      ],
      [$this->userBook(6, [], 2, '9780000000002')],
      self::EDITIONS,
      [],
    );
    $this->assertCount(1, $ops);
    $this->assertSame('skip', $ops[0]['op']);
    $this->assertSame(1, $ops[0]['nid']);
  }

}

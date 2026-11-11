<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Services;

use Psr\Log\LoggerInterface;
use Drupal\books_activity\Services\ActivityStatusService;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\books_hardcover\Sync\ActivitySyncPlanner;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Syncs the site's reading activities with the Hardcover reading log.
 *
 * Loads both sides, lets ActivitySyncPlanner decide what changes, then applies
 * it. The sync keeps no state of its own: readings are matched by date on
 * every run, so an interrupted run (a rate limit, say) simply carries on next
 * time.
 */
class ActivitySyncService {

  /**
   * Name of the lock that keeps cron and Drush from syncing at once.
   */
  protected const LOCK = 'books_hardcover_activity_sync';

  /**
   * Restricts a run to changes on the site.
   */
  public const ONLY_SITE = 'site';

  /**
   * Restricts a run to changes on Hardcover.
   */
  public const ONLY_HARDCOVER = 'hardcover';

  /**
   * Operations that change the site; every other change is on Hardcover.
   */
  protected const SITE_OPS = ['pull', 'update_local'];

  /**
   * Status term ids by name, loaded on demand.
   *
   * @var array<string, int|null>
   */
  protected array $statusIds = [];

  /**
   * Reads each Hardcover library entry should hold, as this run left them.
   *
   * Keyed by user_book id, then read id. A status change can start or finish
   * a read on its own; comparing against this undoes those side effects.
   *
   * @var array<int, array<int, array{started_at: string|null, finished_at: string|null}>>
   */
  protected array $expectedReads = [];

  /**
   * Library entries created during this run, keyed by Hardcover book id.
   *
   * @var array<int, int>
   */
  protected array $createdUserBooks = [];

  /**
   * Book nodes created during this run, keyed by ISBN-13.
   *
   * @var array<string, int>
   */
  protected array $createdBooks = [];

  /**
   * Constructs an ActivitySyncService.
   *
   * @param \Drupal\books_hardcover\Services\HardcoverService $hardcover
   *   The Hardcover API client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\books_activity\Services\ActivityStatusService $activityStatus
   *   Activity status lookups.
   * @param \Drupal\books_catalog\Services\BookService $bookService
   *   Creates book nodes.
   * @param \Drupal\books_hardcover\Services\BookSyncService $bookSync
   *   Queues new books for a Hardcover data sync.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected HardcoverService $hardcover,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ActivityStatusService $activityStatus,
    protected BookService $bookService,
    protected BookSyncService $bookSync,
    protected LockBackendInterface $lock,
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {}

  /**
   * Runs a sync.
   *
   * @param bool $dryRun
   *   Plan only; change nothing on either side.
   * @param callable(int): bool|null $onRateLimit
   *   Called with the wait in seconds when Hardcover throttles a write.
   *   Returning TRUE retries the write (the callable is expected to have
   *   waited); FALSE, or no callable, stops the run.
   * @param string|null $only
   *   ONLY_SITE or ONLY_HARDCOVER to apply one side's changes only; the
   *   other side's are planned but marked held.
   *
   * @return array{ops: array<int, array<string, mixed>>, stopped: string|null}
   *   Every planned operation with a 'result' key (planned, done, failed,
   *   pending, held, reported), and why the run stopped early, if it did.
   *
   * @throws \RuntimeException
   *   When the Hardcover library cannot be read, or a sync is already running.
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When Hardcover throttles the initial reads.
   */
  public function sync(bool $dryRun = FALSE, ?callable $onRateLimit = NULL, ?string $only = NULL): array {
    if (!in_array($only, [NULL, self::ONLY_SITE, self::ONLY_HARDCOVER], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown side "%s".', $only));
    }

    if (!$this->lock->acquire(self::LOCK, 900)) {
      throw new \RuntimeException('An activity sync is already running.');
    }

    try {
      return $this->doSync($dryRun, $onRateLimit, $only);
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Runs a sync once the lock is held.
   *
   * @param bool $dryRun
   *   Plan only.
   * @param callable(int): bool|null $onRateLimit
   *   Rate limit handler, see sync().
   * @param string|null $only
   *   Side restriction, see sync().
   *
   * @return array{ops: array<int, array<string, mixed>>, stopped: string|null}
   *   See sync().
   */
  protected function doSync(bool $dryRun, ?callable $onRateLimit, ?string $only): array {
    $this->expectedReads = [];
    $this->createdUserBooks = [];
    $this->createdBooks = [];

    $log = $this->hardcover->getReadingLog();
    if ($log === NULL) {
      throw new \RuntimeException('Could not read the Hardcover library. Check the API token and the logs.');
    }

    $localBooks = $this->loadLocalBooks();
    $userBooks = $this->normaliseUserBooks($log['user_books']);
    $editions = $this->hardcover->getEditionsByIsbn(array_keys($localBooks));

    $ops = (new ActivitySyncPlanner())->plan($this->loadActivities(), $userBooks, $editions, $localBooks);

    if ($dryRun) {
      foreach ($ops as &$op) {
        $op['result'] = in_array($op['op'], ['conflict', 'skip'], TRUE) ? 'reported' : 'planned';
      }
      return ['ops' => $ops, 'stopped' => NULL];
    }

    $stopped = NULL;
    foreach ($ops as &$op) {
      if (in_array($op['op'], ['conflict', 'skip'], TRUE)) {
        $op['result'] = 'reported';
        continue;
      }
      $onSite = in_array($op['op'], self::SITE_OPS, TRUE);
      if (($only === self::ONLY_SITE && !$onSite) || ($only === self::ONLY_HARDCOVER && $onSite)) {
        $op['result'] = 'held';
        continue;
      }
      if ($stopped !== NULL) {
        $op['result'] = 'pending';
        continue;
      }

      while (TRUE) {
        try {
          $op['result'] = $this->apply($op) ? 'done' : 'failed';
          break;
        }
        catch (HardcoverRateLimitException $e) {
          if ($onRateLimit !== NULL && $onRateLimit($e->getRetryAfter())) {
            continue;
          }
          $stopped = sprintf('Hardcover rate limit reached; it resets in %d s. The rest is picked up by the next run.', $e->getRetryAfter());
          $op['result'] = 'pending';
          break;
        }
        catch (\Exception $e) {
          $this->logger()->error('Activity sync failed on @op for @label: @message', [
            '@op' => $op['op'],
            '@label' => $op['label'] ?? '',
            '@message' => $e->getMessage(),
          ]);
          $op['result'] = 'failed';
          break;
        }
      }
    }
    unset($op);

    return ['ops' => $ops, 'stopped' => $stopped];
  }

  /**
   * Applies one operation.
   *
   * @param array<string, mixed> $op
   *   The operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function apply(array $op): bool {
    return match ($op['op']) {
      'pull' => $this->pull($op),
      'update_local' => $this->updateLocal($op),
      'push' => $this->push($op),
      'update_remote' => $this->updateRemote($op),
      'set_status' => $this->setStatus($op),
      default => FALSE,
    };
  }

  /**
   * Creates a site activity from a Hardcover read.
   *
   * @param array<string, mixed> $op
   *   The pull operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function pull(array $op): bool {
    $bookNid = $op['book_nid'] ?? $this->createdBooks[$op['isbn']] ?? NULL;
    if ($bookNid === NULL) {
      // A stub is enough: the regular book sync queue fills in the data and
      // the cover, exactly as for a book added while Hardcover was down.
      $book = $this->bookService->saveBookData($op['isbn'], [
        'field_isbn' => $op['isbn'],
        'title' => $op['label'],
      ], TRUE);
      $this->bookSync->queueBook($book->id(), $op['isbn']);
      $bookNid = (int) $book->id();
      $this->createdBooks[$op['isbn']] = $bookNid;
    }

    $activity = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'activity',
      'title' => $op['label'],
      'field_book' => ['target_id' => $bookNid],
      'field_start_date' => $op['start'],
      'field_end_date' => $op['end'],
      'field_status' => ['target_id' => $this->statusId($op['status'])],
    ]);
    $activity->save();

    $this->logger()->info('Imported a reading of @label from Hardcover.', ['@label' => $op['label']]);
    return TRUE;
  }

  /**
   * Fills a site activity's gaps from its Hardcover read.
   *
   * @param array<string, mixed> $op
   *   The update_local operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function updateLocal(array $op): bool {
    $activity = $this->entityTypeManager->getStorage('node')->load($op['nid']);
    if (!$activity instanceof NodeInterface) {
      return FALSE;
    }

    $fields = ['start' => 'field_start_date', 'end' => 'field_end_date'];
    foreach ($op['values'] as $key => $value) {
      if ($key === 'status') {
        $activity->set('field_status', ['target_id' => $this->statusId($value)]);
      }
      else {
        $activity->set($fields[$key], $value);
      }
    }
    $activity->save();
    return TRUE;
  }

  /**
   * Creates a Hardcover read from a site activity.
   *
   * @param array<string, mixed> $op
   *   The push operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function push(array $op): bool {
    $dates = [
      'started_at' => $op['start'],
      'finished_at' => $op['end'],
    ];
    if ($op['edition_id'] !== NULL) {
      $dates['edition_id'] = $op['edition_id'];
    }

    $userBookId = $op['user_book_id'] ?? $this->createdUserBooks[$op['book_id']] ?? NULL;
    if ($userBookId !== NULL) {
      $read = $this->hardcover->insertUserBookRead($userBookId, array_filter($dates, static fn($v): bool => $v !== NULL));
      if ($read === NULL) {
        return FALSE;
      }
      $this->expect($userBookId, $read);
      return TRUE;
    }

    $userBook = $this->hardcover->insertUserBook(array_filter([
      'book_id' => $op['book_id'],
      'edition_id' => $op['edition_id'],
      'status_id' => $op['status_id'],
      // Keeps a back-filled reading out of followers' feeds.
      'imported' => TRUE,
    ], static fn($v): bool => $v !== NULL));
    if ($userBook === NULL) {
      return FALSE;
    }
    $userBookId = (int) $userBook['id'];
    $this->createdUserBooks[$op['book_id']] = $userBookId;
    $this->expectedReads[$userBookId] = [];

    // Adding a book as Read or Currently Reading may create a read dated
    // today. Reuse it, with the real dates, rather than adding a second one.
    $autoRead = $userBook['user_book_reads'][0] ?? NULL;
    $read = is_array($autoRead)
      ? $this->hardcover->updateUserBookRead((int) $autoRead['id'], $dates)
      : $this->hardcover->insertUserBookRead($userBookId, array_filter($dates, static fn($v): bool => $v !== NULL));
    if ($read === NULL) {
      return FALSE;
    }
    $this->expect($userBookId, $read);
    return TRUE;
  }

  /**
   * Fills a Hardcover read's missing dates.
   *
   * @param array<string, mixed> $op
   *   The update_remote operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function updateRemote(array $op): bool {
    $read = $this->hardcover->updateUserBookRead($op['read_id'], $op['dates']);
    if ($read === NULL) {
      return FALSE;
    }
    foreach ($this->expectedReads as $userBookId => $reads) {
      if (isset($reads[$op['read_id']])) {
        $this->expect($userBookId, $read);
      }
    }
    return TRUE;
  }

  /**
   * Changes a Hardcover library status, then undoes its side effects.
   *
   * @param array<string, mixed> $op
   *   The set_status operation.
   *
   * @return bool
   *   TRUE on success.
   */
  protected function setStatus(array $op): bool {
    $userBookId = $op['user_book_id'];
    $userBook = $this->hardcover->updateUserBook($userBookId, ['status_id' => $op['status_id']]);
    if ($userBook === NULL) {
      return FALSE;
    }

    $expected = $this->expectedReads[$userBookId] ?? [];
    foreach ($userBook['user_book_reads'] ?? [] as $read) {
      $id = (int) $read['id'];
      if (!isset($expected[$id])) {
        // Started or finished by the status change itself, dated today.
        $this->hardcover->deleteUserBookRead($id);
        continue;
      }
      $changes = array_diff_assoc(
        array_map('strval', $expected[$id]),
        array_map('strval', ['started_at' => $read['started_at'], 'finished_at' => $read['finished_at']]),
      );
      if ($changes !== []) {
        $this->hardcover->updateUserBookRead($id, array_intersect_key($expected[$id], $changes));
      }
    }
    return TRUE;
  }

  /**
   * Records the dates a Hardcover read should hold.
   *
   * @param int $userBookId
   *   The library entry.
   * @param array<string, mixed> $read
   *   The read as returned by Hardcover.
   */
  protected function expect(int $userBookId, array $read): void {
    $this->expectedReads[$userBookId][(int) $read['id']] = [
      'started_at' => $read['started_at'] ?? NULL,
      'finished_at' => $read['finished_at'] ?? NULL,
    ];
  }

  /**
   * Loads the site's activities in the planner's shape.
   *
   * @return array<int, array{nid: int, book_nid: int|null, label: string, isbn: string|null, status: string|null, start: string|null, end: string|null}>
   *   The activities.
   */
  protected function loadActivities(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'activity')
      ->accessCheck(FALSE)
      ->execute();

    $activities = [];
    foreach ($storage->loadMultiple($ids) as $activity) {
      $book = $activity->get('field_book')->entity;
      $isbn = $book instanceof NodeInterface ? $book->get('field_isbn')->value : NULL;
      $activities[] = [
        'nid' => (int) $activity->id(),
        'book_nid' => $book ? (int) $book->id() : NULL,
        'label' => (string) $activity->label(),
        'isbn' => $isbn ? $this->hardcover->normaliseToIsbn13((string) $isbn) : NULL,
        'status' => $activity->get('field_status')->entity?->label(),
        'start' => $activity->get('field_start_date')->value ?: NULL,
        'end' => $activity->get('field_end_date')->value ?: NULL,
      ];
    }
    return $activities;
  }

  /**
   * Loads the site's books as ISBN-13 => node id.
   *
   * @return array<int|string, int>
   *   The books (int keys, as PHP casts digit-only ISBNs).
   */
  protected function loadLocalBooks(): array {
    $books = [];
    foreach ($this->bookService->getAllBooks() as $nid) {
      $book = $this->entityTypeManager->getStorage('node')->load($nid);
      $isbn = $book instanceof NodeInterface ? $book->get('field_isbn')->value : NULL;
      if ($isbn) {
        $books[$this->hardcover->normaliseToIsbn13((string) $isbn)] = (int) $nid;
      }
    }
    return $books;
  }

  /**
   * Converts raw user_books rows to the planner's shape.
   *
   * Also seeds the reads each entry is known to hold.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Raw rows from the API.
   *
   * @return array<int, array{id: int, book_id: int, edition_id: int|null, status_id: int, title: string, isbn: string|null, reads: array<int, array{id: int, start: string|null, end: string|null, isbn: string|null}>}>
   *   The library.
   */
  protected function normaliseUserBooks(array $rows): array {
    $userBooks = [];
    foreach ($rows as $row) {
      $reads = [];
      foreach ($row['user_book_reads'] ?? [] as $read) {
        $reads[] = [
          'id' => (int) $read['id'],
          'start' => $read['started_at'] ?? NULL,
          'end' => $read['finished_at'] ?? NULL,
          'isbn' => $read['edition']['isbn_13'] ?? NULL,
        ];
        $this->expect((int) $row['id'], $read);
      }
      $this->expectedReads[(int) $row['id']] ??= [];
      $userBooks[] = [
        'id' => (int) $row['id'],
        'book_id' => (int) $row['book_id'],
        'edition_id' => isset($row['edition_id']) ? (int) $row['edition_id'] : NULL,
        'status_id' => (int) $row['status_id'],
        'title' => (string) ($row['book']['title'] ?? ''),
        'isbn' => $row['edition']['isbn_13'] ?? NULL,
        'reads' => $reads,
      ];
    }
    return $userBooks;
  }

  /**
   * Gets a status term id by name, cached for the run.
   *
   * @param string $name
   *   The status name.
   *
   * @return int|null
   *   The term id.
   */
  protected function statusId(string $name): ?int {
    if (!array_key_exists($name, $this->statusIds)) {
      $this->statusIds[$name] = $this->activityStatus->getStatusId($name);
    }
    return $this->statusIds[$name];
  }

  /**
   * Gets the logger channel.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger.
   */
  protected function logger(): LoggerInterface {
    return $this->loggerChannelFactory->get('HardcoverActivitySync');
  }

}

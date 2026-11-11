<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Sync;

/**
 * Works out what an activity sync with Hardcover has to change.
 *
 * Pure: it compares a snapshot of the site's activities with a snapshot of the
 * Hardcover reading log and returns a list of operations, without any I/O.
 *
 * The rules, in short:
 * - A site activity belongs to the Hardcover book of its ISBN. When Hardcover
 *   does not know that ISBN (the site holds another edition), the activity
 *   belongs to the one library book with the same title, if there is one.
 * - A site activity and a Hardcover read are the same reading when they are
 *   for the same Hardcover book and share a start or an end date. When one
 *   reading is left unmatched on each side of a book, those two are paired.
 *   Anything more ambiguous is reported and left alone.
 * - Gaps are filled in both directions; a date set on both sides is never
 *   overwritten. Disagreements are reported as conflicts.
 * - Readings missing on one side are created there.
 * - Nothing is ever deleted.
 *
 * Each operation is an array with an 'op' key:
 * - push: create a read on Hardcover (and the library entry when missing).
 * - update_remote: fill missing dates on a Hardcover read.
 * - set_status: align a Hardcover library status with the latest reading.
 * - pull: create a site activity (and the book when missing).
 * - update_local: fill missing dates, and the status, on a site activity.
 * - conflict: both sides hold a different value; reported only.
 * - skip: a reading that cannot be synced, with the reason.
 *
 * @phpstan-type LocalActivity array{nid: int, label: string, isbn: string|null, status: string|null, start: string|null, end: string|null, book_nid?: int|null, edition_id?: int|null}
 * @phpstan-type RemoteRead array{id: int, start: string|null, end: string|null, isbn: string|null}
 * @phpstan-type RemoteUserBook array{id: int, book_id: int, edition_id: int|null, status_id: int, title: string, isbn: string|null, reads: array<int, RemoteRead>}
 * @phpstan-type Edition array{edition_id: int, book_id: int}
 */
class ActivitySyncPlanner {

  /**
   * Site status: being read.
   */
  public const READING = 'Reading';

  /**
   * Site status: read to the end.
   */
  public const FINISHED = 'Finished';

  /**
   * Site status: given up.
   */
  public const ABANDONED = 'Abandoned';

  /**
   * Hardcover status: want to read.
   */
  public const HC_WANT_TO_READ = 1;

  /**
   * Hardcover status: currently reading.
   */
  public const HC_READING = 2;

  /**
   * Hardcover status: read.
   */
  public const HC_READ = 3;

  /**
   * Hardcover status: paused.
   */
  public const HC_PAUSED = 4;

  /**
   * Hardcover status: did not finish.
   */
  public const HC_DNF = 5;

  /**
   * Hardcover status: ignored. Never touched by the sync.
   */
  public const HC_IGNORED = 6;

  /**
   * Site status => Hardcover status.
   */
  protected const STATUS_MAP = [
    self::READING => self::HC_READING,
    self::FINISHED => self::HC_READ,
    self::ABANDONED => self::HC_DNF,
  ];

  /**
   * Plans a sync.
   *
   * @param array<int, LocalActivity> $activities
   *   The site's activities. Statuses outside STATUS_MAP (To Read) are
   *   ignored.
   * @param array<int, RemoteUserBook> $userBooks
   *   The Hardcover library.
   * @param array<int|string, Edition> $editions
   *   Hardcover editions keyed by ISBN-13, covering every site book's ISBN.
   *   Keys are ints when PHP casts the digit-only ISBN.
   * @param array<int|string, int> $localBooks
   *   Site book node ids keyed by ISBN-13, likewise.
   *
   * @return array<int, array<string, mixed>>
   *   Operations, in the order they should run.
   */
  public function plan(array $activities, array $userBooks, array $editions, array $localBooks): array {
    $ops = [];

    $userBookByBook = [];
    $userBooksByTitle = [];
    foreach ($userBooks as $userBook) {
      $userBookByBook[$userBook['book_id']] = $userBook;
      $userBooksByTitle[self::normaliseTitle($userBook['title'])][] = $userBook;
    }

    // Site books keyed by Hardcover book, to find a pulled reading's book.
    $localBookByHcBook = [];
    foreach ($localBooks as $isbn => $nid) {
      if (isset($editions[$isbn])) {
        $localBookByHcBook[$editions[$isbn]['book_id']] ??= $nid;
      }
    }

    // Site activities grouped by the Hardcover book they are a reading of.
    $localByBook = [];
    foreach ($activities as $activity) {
      if (!isset(self::STATUS_MAP[$activity['status'] ?? ''])) {
        continue;
      }
      if ($activity['start'] === NULL && $activity['end'] === NULL) {
        $ops[] = $this->skip('The activity has no dates.', $activity['label'], nid: $activity['nid']);
        continue;
      }
      $edition = $editions[$activity['isbn'] ?? ''] ?? NULL;
      if ($edition === NULL) {
        $sameTitle = $userBooksByTitle[self::normaliseTitle($activity['label'])] ?? [];
        if (count($sameTitle) !== 1) {
          $ops[] = $this->skip('Hardcover does not know this ISBN, and no single book in the Hardcover library has this title.', $activity['label'], nid: $activity['nid']);
          continue;
        }
        $edition = ['book_id' => $sameTitle[0]['book_id'], 'edition_id' => $sameTitle[0]['edition_id']];
        if (!empty($activity['book_nid'])) {
          $localBookByHcBook[$edition['book_id']] ??= $activity['book_nid'];
        }
      }
      $activity['edition_id'] = $edition['edition_id'];
      $localByBook[$edition['book_id']][] = $activity;
    }

    $bookIds = array_unique(array_merge(array_keys($localByBook), array_keys($userBookByBook)));
    sort($bookIds);

    foreach ($bookIds as $bookId) {
      $userBook = $userBookByBook[$bookId] ?? NULL;
      if ($userBook !== NULL && $userBook['status_id'] === self::HC_IGNORED) {
        continue;
      }
      array_push($ops, ...$this->planBook(
        $localByBook[$bookId] ?? [],
        $userBook,
        $bookId,
        $editions,
        $localBookByHcBook[$bookId] ?? NULL,
        $localBooks,
      ));
    }

    // Writes to the site first, then reads on Hardcover, then statuses: a
    // status change can start or finish a read on its own, so it has to see
    // the reads already in place.
    $order = array_flip(['pull', 'update_local', 'push', 'update_remote', 'set_status', 'conflict', 'skip']);
    usort($ops, static fn(array $a, array $b): int => $order[$a['op']] <=> $order[$b['op']]);

    return $ops;
  }

  /**
   * Plans the readings of one Hardcover book.
   *
   * @param array<int, LocalActivity> $locals
   *   Site activities for the book, with the edition to record a push under.
   * @param RemoteUserBook|null $userBook
   *   The library entry, if the book is in the Hardcover library.
   * @param int $bookId
   *   The Hardcover book id.
   * @param array<int|string, Edition> $editions
   *   Hardcover editions keyed by ISBN-13.
   * @param int|null $localBookNid
   *   The site's node for this book, if any.
   * @param array<int|string, int> $localBooks
   *   Site book node ids keyed by ISBN-13.
   *
   * @return array<int, array<string, mixed>>
   *   Operations.
   */
  protected function planBook(array $locals, ?array $userBook, int $bookId, array $editions, ?int $localBookNid, array $localBooks): array {
    $ops = [];
    $remotes = $userBook['reads'] ?? [];
    $title = $userBook['title'] ?? ($locals[0]['label'] ?? '');

    [$pairs, $locals, $remotes] = $this->match($locals, $remotes);

    // Ambiguous: several readings unmatched on both sides. Guessing would
    // risk duplicating or crossing readings, so they are reported instead.
    if (count($locals) > 1 && count($remotes) > 1) {
      $ops[] = $this->skip(sprintf('%d readings on the site and %d on Hardcover could not be matched by date.', count($locals), count($remotes)), $title);
      $locals = [];
      $remotes = [];
    }

    $latestRemote = $this->latestRead($userBook['reads'] ?? []);

    // Site readings as they will stand once gaps are filled, which is what
    // the Hardcover status has to follow.
    $effective = $locals;
    foreach ($pairs as [$local, $remote]) {
      array_push($ops, ...$this->reconcile($local, $remote, $userBook, $latestRemote));
      $effective[] = $this->merged($local, $remote, $userBook, $latestRemote);
    }
    $latestLocal = $this->latestLocal($effective);
    $wanted = $latestLocal !== NULL ? self::STATUS_MAP[$latestLocal['status']] : NULL;

    foreach ($locals as $local) {
      $ops[] = [
        'op' => 'push',
        'nid' => $local['nid'],
        'label' => $local['label'],
        'book_id' => $bookId,
        'edition_id' => $local['edition_id'] ?? NULL,
        'user_book_id' => $userBook['id'] ?? NULL,
        'start' => $local['start'],
        'end' => $local['end'],
        // Used only when the push creates the library entry: the entry's
        // status describes the book's latest reading, not this one.
        'status_id' => $wanted,
      ];
    }

    foreach ($remotes as $remote) {
      $status = $this->remoteReadStatus($remote, $userBook, $latestRemote);
      if ($status === NULL) {
        $ops[] = $this->skip('The Hardcover read is open but the book is not being read.', $title, readId: $remote['id']);
        continue;
      }
      if ($remote['start'] === NULL && $remote['end'] === NULL) {
        $ops[] = $this->skip('The Hardcover read has no dates.', $title, readId: $remote['id']);
        continue;
      }
      $isbn = $remote['isbn'] ?? $userBook['isbn'] ?? NULL;
      $nid = $localBookNid ?? ($isbn !== NULL ? ($localBooks[$isbn] ?? NULL) : NULL);
      if ($nid === NULL && $isbn === NULL) {
        $ops[] = $this->skip('The Hardcover edition has no ISBN-13, so the book cannot be created on the site.', $title, readId: $remote['id']);
        continue;
      }
      $ops[] = [
        'op' => 'pull',
        'read_id' => $remote['id'],
        'label' => $title,
        'book_nid' => $nid,
        'isbn' => $isbn,
        'start' => $remote['start'],
        'end' => $remote['end'],
        'status' => $status,
      ];
    }

    // The Hardcover status follows the latest site reading, when the site
    // holds that reading. A pulled reading derives its status from Hardcover,
    // so there is nothing to align then.
    if ($latestLocal !== NULL && ($latestRemote === NULL || $this->sortKey($latestLocal) >= $this->sortKey($latestRemote))) {
      if ($userBook !== NULL && $userBook['status_id'] !== $wanted) {
        $ops[] = [
          'op' => 'set_status',
          'user_book_id' => $userBook['id'],
          'book_id' => $bookId,
          'label' => $title,
          'status_id' => $wanted,
          'from_status_id' => $userBook['status_id'],
        ];
      }
    }

    return $ops;
  }

  /**
   * Pairs site activities with Hardcover reads of the same book.
   *
   * @param array<int, LocalActivity> $locals
   *   Site activities.
   * @param array<int, RemoteRead> $remotes
   *   Hardcover reads.
   *
   * @return array{0: array<int, array{0: LocalActivity, 1: RemoteRead}>, 1: array<int, LocalActivity>, 2: array<int, RemoteRead>}
   *   The pairs, then the unmatched activities and reads.
   */
  protected function match(array $locals, array $remotes): array {
    $pairs = [];

    foreach ($locals as $l => $local) {
      foreach ($remotes as $r => $remote) {
        $sameEnd = $local['end'] !== NULL && $local['end'] === $remote['end'];
        $sameStart = $local['start'] !== NULL && $local['start'] === $remote['start'];
        if ($sameEnd || $sameStart) {
          $pairs[] = [$local, $remote];
          unset($locals[$l], $remotes[$r]);
          continue 2;
        }
      }
    }

    if (count($locals) === 1 && count($remotes) === 1) {
      $pairs[] = [reset($locals), reset($remotes)];
      $locals = [];
      $remotes = [];
    }

    return [$pairs, array_values($locals), array_values($remotes)];
  }

  /**
   * Fills the gaps between a matched activity and read.
   *
   * @param LocalActivity $local
   *   The site activity.
   * @param RemoteRead $remote
   *   The Hardcover read.
   * @param RemoteUserBook|null $userBook
   *   The library entry.
   * @param RemoteRead|null $latestRemote
   *   The book's latest Hardcover read.
   *
   * @return array<int, array<string, mixed>>
   *   Operations.
   */
  protected function reconcile(array $local, array $remote, ?array $userBook, ?array $latestRemote): array {
    $ops = [];
    $toRemote = [];
    $toLocal = [];

    foreach (['start' => 'started_at', 'end' => 'finished_at'] as $key => $remoteField) {
      if ($local[$key] !== NULL && $remote[$key] === NULL) {
        $toRemote[$remoteField] = $local[$key];
      }
      elseif ($local[$key] === NULL && $remote[$key] !== NULL) {
        $toLocal[$key] = $remote[$key];
      }
      elseif ($local[$key] !== $remote[$key]) {
        $ops[] = [
          'op' => 'conflict',
          'nid' => $local['nid'],
          'read_id' => $remote['id'],
          'label' => $local['label'],
          'field' => $key,
          'local' => $local[$key],
          'remote' => $remote[$key],
        ];
      }
    }

    // A reading closed on Hardcover closes the site activity too.
    if (isset($toLocal['end'])) {
      $toLocal['status'] = $this->remoteReadStatus($remote, $userBook, $latestRemote) ?? self::FINISHED;
    }

    if ($toRemote !== []) {
      $ops[] = [
        'op' => 'update_remote',
        'nid' => $local['nid'],
        'read_id' => $remote['id'],
        'label' => $local['label'],
        'dates' => $toRemote,
      ];
    }
    if ($toLocal !== []) {
      $ops[] = [
        'op' => 'update_local',
        'nid' => $local['nid'],
        'read_id' => $remote['id'],
        'label' => $local['label'],
        'values' => $toLocal,
      ];
    }

    return $ops;
  }

  /**
   * Gives a matched activity as it will stand once its gaps are filled.
   *
   * @param LocalActivity $local
   *   The site activity.
   * @param RemoteRead $remote
   *   The Hardcover read.
   * @param RemoteUserBook|null $userBook
   *   The library entry.
   * @param RemoteRead|null $latestRemote
   *   The book's latest Hardcover read.
   *
   * @return LocalActivity
   *   The activity with its missing dates and status taken from the read.
   */
  protected function merged(array $local, array $remote, ?array $userBook, ?array $latestRemote): array {
    if ($local['end'] === NULL && $remote['end'] !== NULL) {
      $local['status'] = $this->remoteReadStatus($remote, $userBook, $latestRemote) ?? self::FINISHED;
    }
    $local['start'] ??= $remote['start'];
    $local['end'] ??= $remote['end'];
    return $local;
  }

  /**
   * Derives the site status of a Hardcover read.
   *
   * @param RemoteRead $read
   *   The read.
   * @param RemoteUserBook|null $userBook
   *   Its library entry; the status there describes the latest read only.
   * @param RemoteRead|null $latestRemote
   *   The book's latest read.
   *
   * @return string|null
   *   A site status, or NULL for an open read on a book not being read.
   */
  protected function remoteReadStatus(array $read, ?array $userBook, ?array $latestRemote): ?string {
    $isLatest = $latestRemote !== NULL && $latestRemote['id'] === $read['id'];
    $status = $userBook['status_id'] ?? NULL;

    if ($read['end'] !== NULL) {
      return $isLatest && $status === self::HC_DNF ? self::ABANDONED : self::FINISHED;
    }

    return $isLatest && in_array($status, [self::HC_READING, self::HC_PAUSED], TRUE) ? self::READING : NULL;
  }

  /**
   * Picks the latest of a set of Hardcover reads.
   *
   * @param array<int, RemoteRead> $reads
   *   The reads.
   *
   * @return RemoteRead|null
   *   The latest read, or NULL when there are none.
   */
  protected function latestRead(array $reads): ?array {
    $latest = NULL;
    foreach ($reads as $read) {
      if ($latest === NULL || $this->sortKey($read) >= $this->sortKey($latest)) {
        $latest = $read;
      }
    }
    return $latest;
  }

  /**
   * Picks the latest of a set of site activities.
   *
   * @param array<int, LocalActivity> $activities
   *   The activities.
   *
   * @return LocalActivity|null
   *   The latest activity, or NULL when there are none.
   */
  protected function latestLocal(array $activities): ?array {
    $latest = NULL;
    foreach ($activities as $activity) {
      if ($latest === NULL || $this->sortKey($activity) >= $this->sortKey($latest)) {
        $latest = $activity;
      }
    }
    return $latest;
  }

  /**
   * Orders readings in time: an open reading sorts after any closed one.
   *
   * @param array{start: string|null, end: string|null} $reading
   *   An activity or a read.
   *
   * @return string
   *   A sortable key.
   */
  protected function sortKey(array $reading): string {
    return ($reading['end'] ?? '9999-99-99') . ($reading['start'] ?? '0000-00-00');
  }

  /**
   * Normalises a title for comparison.
   *
   * The site files titles library-style ("Fated Sky, The") while Hardcover
   * does not ("The Fated Sky"), so articles, case and punctuation are dropped.
   *
   * @param string $title
   *   The title.
   *
   * @return string
   *   The comparison key.
   */
  public static function normaliseTitle(string $title): string {
    $title = mb_strtolower(trim($title));
    $title = preg_replace('/,\s*(the|an|a)$/u', '', $title);
    $title = preg_replace('/^(the|an|a)\s+/u', '', $title);
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
  }

  /**
   * Builds a skip operation.
   *
   * @param string $reason
   *   Why the reading is skipped.
   * @param string $label
   *   The book title.
   * @param int|null $nid
   *   The site activity, if any.
   * @param int|null $readId
   *   The Hardcover read, if any.
   *
   * @return array<string, mixed>
   *   The operation.
   */
  protected function skip(string $reason, string $label, ?int $nid = NULL, ?int $readId = NULL): array {
    return [
      'op' => 'skip',
      'label' => $label,
      'reason' => $reason,
      'nid' => $nid,
      'read_id' => $readId,
    ];
  }

}

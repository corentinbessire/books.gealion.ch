<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Enqueues books for a Hardcover sync.
 *
 * Queueing is a Hardcover concern, not a book-domain one, so it lives here
 * rather than on the catalog's BookService.
 */
class BookSyncService {

  /**
   * Machine name of the sync queue.
   *
   * Also the id of the QueueWorker plugin that drains it, and the value stored
   * in the queue table — it must not change without draining first.
   */
  public const QUEUE = 'hardcover_book_sync';

  /**
   * Constructs a BookSyncService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Queues book nodes for a Hardcover sync.
   *
   * @param array<int|string> $nids
   *   Node IDs to queue.
   *
   * @return int
   *   Number of items queued.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function queueBooksForSync(array $nids): int {
    $queue = $this->queueFactory->get(self::QUEUE);
    $queued = 0;

    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      if ($node->bundle() !== 'book') {
        continue;
      }
      $isbn = $node->get('field_isbn')->value;
      if (empty($isbn)) {
        continue;
      }
      $queue->createItem([
        'nid' => (int) $node->id(),
        'isbn' => $isbn,
        'only_fill_gaps' => TRUE,
      ]);
      $queued++;
    }

    return $queued;
  }

  /**
   * Queues one book for a Hardcover sync.
   *
   * Used when an import could not complete inline — a rate limit, say — so the
   * book is saved as a stub and filled in later.
   *
   * @param int|string $nid
   *   The book node id.
   * @param string $isbn
   *   The book's ISBN.
   */
  public function queueBook(int|string $nid, string $isbn): void {
    $this->queueFactory->get(self::QUEUE)->createItem([
      'nid' => (int) $nid,
      'isbn' => $isbn,
      'only_fill_gaps' => TRUE,
    ]);
  }

}

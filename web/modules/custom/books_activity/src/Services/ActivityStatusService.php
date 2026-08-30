<?php

declare(strict_types=1);

namespace Drupal\books_activity\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Single source of truth for what an activity's status means.
 *
 * Both the controller guard and the reading-log buttons ask this service
 * whether an activity is still being read. Keeping the rule in one place is
 * what stops the button and the guard drifting apart, which would be an
 * invisible bug: the UI would say one thing and the server do another.
 */
class ActivityStatusService {

  /**
   * Vocabulary holding the activity statuses.
   */
  public const VOCABULARY = 'sta';

  /**
   * Name of the status meaning the book is currently being read.
   */
  public const READING = 'Reading';

  /**
   * The book has never been read; offer to start it.
   */
  public const ACTION_START = 'start';

  /**
   * The book has been read before and is not open; offer to read it again.
   */
  public const ACTION_REREAD = 'reread';

  /**
   * Constructs an ActivityStatusService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets a status term id by name.
   *
   * Access checking is deliberately off: a status term id is internal
   * bookkeeping, not content the current user is browsing, so the answer must
   * not change with the viewer's permissions.
   *
   * @param string $name
   *   The status name, for example Reading.
   *
   * @return int|null
   *   The term id, or NULL when no such status exists.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getStatusId(string $name): ?int {
    $result = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('name', $name)
      ->condition('vid', self::VOCABULARY)
      ->accessCheck(FALSE)
      ->execute();

    return empty($result) ? NULL : (int) reset($result);
  }

  /**
   * Checks whether an activity is currently being read.
   *
   * @param \Drupal\node\NodeInterface $activity
   *   The activity node.
   *
   * @return bool
   *   TRUE when its status is Reading.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function isReading(NodeInterface $activity): bool {
    if (!$activity->hasField('field_status') || $activity->get('field_status')->isEmpty()) {
      return FALSE;
    }

    $reading = $this->getStatusId(self::READING);

    return $reading !== NULL
      && (int) $activity->get('field_status')->target_id === $reading;
  }

  /**
   * Decides which start action a book should offer, if any.
   *
   * @param \Drupal\node\NodeInterface $book
   *   The book node.
   *
   * @return string|null
   *   ACTION_START when the book has no activities, ACTION_REREAD when every
   *   activity is closed, or NULL while one is still open — a second reading
   *   activity for the same book would be a duplicate.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getStartAction(NodeInterface $book): ?string {
    $storage = $this->entityTypeManager->getStorage('node');

    $ids = $storage->getQuery()
      ->condition('type', 'activity')
      ->condition('field_book', $book->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return self::ACTION_START;
    }

    foreach ($storage->loadMultiple($ids) as $activity) {
      if ($this->isReading($activity)) {
        return NULL;
      }
    }

    return self::ACTION_REREAD;
  }

}

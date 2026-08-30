<?php

namespace Drupal\books_activity\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Entity and theme hooks for reading activities.
 */
class ActivityHooks {

  /**
   * Implements hook_node_presave().
   *
   * An activity is always titled after the book it tracks, so the title is
   * derived rather than entered.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($node->bundle() !== 'activity') {
      return;
    }

    // Guarded rather than assumed: Drupal throws for a field the bundle does
    // not have, which would make an activity unsaveable anywhere field_book
    // is not configured.
    if (!$node->hasField('field_book')) {
      return;
    }

    if ($book = $node->get('field_book')->entity) {
      $node->set('title', $book->label());
    }
  }

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, mixed>>
   *   Theme definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'activity_actions' => [
        'variables' => [
          'finish_url' => NULL,
          'abandon_url' => NULL,
        ],
      ],
      'book_start_action' => [
        'variables' => [
          'action' => NULL,
          'url' => NULL,
        ],
      ],
    ];
  }

}

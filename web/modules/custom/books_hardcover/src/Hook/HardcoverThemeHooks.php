<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Theme hooks for the Hardcover import UI.
 */
class HardcoverThemeHooks {

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, mixed>>
   *   Theme definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'isbn_scanner' => [
        'variables' => [
          'scan_label' => NULL,
          'hint' => NULL,
          'close_label' => NULL,
          'starting_label' => NULL,
        ],
      ],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\books_activity_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Stands in for the activities view route in kernel tests.
 */
class StubActivitiesController extends ControllerBase {

  /**
   * Returns an empty page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    return ['#markup' => 'activities'];
  }

}

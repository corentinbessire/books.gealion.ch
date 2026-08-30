<?php

namespace Drupal\Tests\books_activity\Kernel;

use Drupal\books_activity\Controller\ActivityController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Kernel tests for the server-side status guard on activity transitions.
 *
 * Hiding the buttons is presentation; this guard is what actually stops a
 * closed activity having its end date overwritten by a stray request.
 *
 * @group books_activity
 * @coversDefaultClass \Drupal\books_activity\Controller\ActivityController
 */
class ActivityStatusGuardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'field',
    'text',
    'file',
    'user',
    'taxonomy',
    'datetime',
    'isbn',
    'books_activity',
    'books_activity_test',
    'books_book_managment',
  ];

  /**
   * The controller under test.
   *
   * @var \Drupal\books_activity\Controller\ActivityController
   */
  protected ActivityController $controller;

  /**
   * Status term ids keyed by name.
   *
   * @var array
   */
  protected array $status = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'field']);

    $this->setUpCurrentUser(['uid' => 1]);

    NodeType::create(['type' => 'book', 'name' => 'Book'])->save();
    NodeType::create(['type' => 'activity', 'name' => 'Activity'])->save();

    Vocabulary::create(['vid' => 'sta', 'name' => 'Status'])->save();
    foreach (['Reading', 'Finished', 'Abandoned'] as $name) {
      $term = Term::create(['vid' => 'sta', 'name' => $name]);
      $term->save();
      $this->status[$name] = $term->id();
    }

    FieldStorageConfig::create([
      'field_name' => 'field_book',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_book',
      'entity_type' => 'node',
      'bundle' => 'activity',
      'label' => 'Book',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'node',
      'bundle' => 'activity',
      'label' => 'Status',
    ])->save();

    foreach (['field_start_date', 'field_end_date'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'datetime',
        'settings' => ['datetime_type' => 'date'],
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'activity',
        'label' => $fieldName,
      ])->save();
    }

    $this->controller = ActivityController::create($this->container);
  }

  /**
   * Creates an activity in a given status.
   *
   * @param string $statusName
   *   One of Reading, Finished, Abandoned.
   * @param string|null $endDate
   *   Optional end date, Y-m-d.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved activity.
   */
  protected function createActivity(string $statusName, ?string $endDate = NULL): Node {
    $activity = Node::create([
      'type' => 'activity',
      'title' => 'Some book',
      'field_start_date' => '2026-01-01',
      'field_status' => ['target_id' => $this->status[$statusName]],
    ]);
    if ($endDate !== NULL) {
      $activity->set('field_end_date', $endDate);
    }
    $activity->save();
    return $activity;
  }

  /**
   * Reloads an activity from storage.
   *
   * @param int $id
   *   Node id.
   *
   * @return \Drupal\node\NodeInterface
   *   The reloaded node.
   */
  protected function reload(int $id) {
    return $this->container->get('entity_type.manager')
      ->getStorage('node')->loadUnchanged($id);
  }

  /**
   * Tests that finishing an activity that is being read works.
   *
   * @covers ::finish
   */
  public function testFinishAppliesToReadingActivity(): void {
    $activity = $this->createActivity('Reading');

    $this->controller->finish($activity);

    $saved = $this->reload((int) $activity->id());
    $this->assertSame($this->status['Finished'], $saved->get('field_status')->target_id);
    $this->assertSame(date('Y-m-d'), $saved->get('field_end_date')->value);
  }

  /**
   * Tests that a finished activity cannot be finished again.
   *
   * Without this guard a stray request rewrites the end date with today,
   * silently losing when the book was actually finished.
   *
   * @covers ::finish
   */
  public function testFinishRefusesAlreadyFinishedActivity(): void {
    $activity = $this->createActivity('Finished', '2020-05-05');

    $this->controller->finish($activity);

    $saved = $this->reload((int) $activity->id());
    $this->assertSame('2020-05-05', $saved->get('field_end_date')->value);
    $this->assertSame($this->status['Finished'], $saved->get('field_status')->target_id);
  }

  /**
   * Tests that abandoning an activity that is being read works.
   *
   * @covers ::abandon
   */
  public function testAbandonAppliesToReadingActivity(): void {
    $activity = $this->createActivity('Reading');

    $this->controller->abandon($activity);

    $saved = $this->reload((int) $activity->id());
    $this->assertSame($this->status['Abandoned'], $saved->get('field_status')->target_id);
    $this->assertSame(date('Y-m-d'), $saved->get('field_end_date')->value);
  }

  /**
   * Tests that an abandoned activity cannot be abandoned again.
   *
   * @covers ::abandon
   */
  public function testAbandonRefusesAlreadyAbandonedActivity(): void {
    $activity = $this->createActivity('Abandoned', '2019-03-03');

    $this->controller->abandon($activity);

    $saved = $this->reload((int) $activity->id());
    $this->assertSame('2019-03-03', $saved->get('field_end_date')->value);
  }

  /**
   * Tests that a finished activity cannot be flipped to abandoned.
   *
   * @covers ::abandon
   */
  public function testAbandonRefusesFinishedActivity(): void {
    $activity = $this->createActivity('Finished', '2021-07-07');

    $this->controller->abandon($activity);

    $saved = $this->reload((int) $activity->id());
    $this->assertSame($this->status['Finished'], $saved->get('field_status')->target_id);
    $this->assertSame('2021-07-07', $saved->get('field_end_date')->value);
  }

}

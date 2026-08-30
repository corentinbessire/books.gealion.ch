<?php

declare(strict_types=1);

namespace Drupal\Tests\books_activity\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Kernel tests for the shared activity status service.
 *
 * The controller guard and the reading-log buttons must agree on what
 * "currently reading" means; both read it from here so they cannot drift.
 *
 * @group books_activity
 * @coversDefaultClass \Drupal\books_activity\Services\ActivityStatusService
 */
class ActivityStatusServiceTest extends KernelTestBase {

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
    'books_catalog',
  ];

  /**
   * Status term ids keyed by name.
   *
   * @var array<string, string>
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

    NodeType::create(['type' => 'activity', 'name' => 'Activity'])->save();
    NodeType::create(['type' => 'book', 'name' => 'Book'])->save();
    Vocabulary::create(['vid' => 'sta', 'name' => 'Status'])->save();
    foreach (['Reading', 'Finished', 'Abandoned'] as $name) {
      $term = Term::create(['vid' => 'sta', 'name' => $name]);
      $term->save();
      $this->status[$name] = $term->id();
    }

    // The presave hook reads field_book on every activity.
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
  }

  /**
   * Builds an activity node in the given status.
   *
   * @param string|null $statusName
   *   Status name, or NULL to leave the field empty.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved activity.
   */
  protected function activity(?string $statusName): Node {
    $values = ['type' => 'activity', 'title' => 'Some book'];
    if ($statusName !== NULL) {
      $values['field_status'] = ['target_id' => $this->status[$statusName]];
    }
    $activity = Node::create($values);
    $activity->save();
    return $activity;
  }

  /**
   * Tests that an activity being read is reported as reading.
   *
   * @covers ::isReading
   */
  public function testIsReadingTrueForReadingActivity(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertTrue($service->isReading($this->activity('Reading')));
  }

  /**
   * Tests that closed activities are not reported as reading.
   *
   * @covers ::isReading
   */
  public function testIsReadingFalseForClosedActivities(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertFalse($service->isReading($this->activity('Finished')));
    $this->assertFalse($service->isReading($this->activity('Abandoned')));
  }

  /**
   * Tests that an activity with no status is not reported as reading.
   *
   * @covers ::isReading
   */
  public function testIsReadingFalseWhenStatusEmpty(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertFalse($service->isReading($this->activity(NULL)));
  }

  /**
   * Tests that the status term id lookup resolves by name.
   *
   * @covers ::getStatusId
   */
  public function testGetStatusIdResolvesByName(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertSame($this->status['Finished'], (string) $service->getStatusId('Finished'));
    $this->assertNull($service->getStatusId('Nonexistent'));
  }

  /**
   * Creates a book with activities in the given statuses.
   *
   * @param string[] $statusNames
   *   Statuses of the activities to attach, may be empty.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved book.
   */
  protected function bookWithActivities(array $statusNames): Node {
    $book = Node::create(['type' => 'book', 'title' => 'Dune']);
    $book->save();

    foreach ($statusNames as $statusName) {
      Node::create([
        'type' => 'activity',
        'title' => 'Dune',
        'field_book' => ['target_id' => $book->id()],
        'field_status' => ['target_id' => $this->status[$statusName]],
      ])->save();
    }

    return $book;
  }

  /**
   * Tests that a book never read offers the start action.
   *
   * @covers ::getStartAction
   */
  public function testGetStartActionIsStartForUnreadBook(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertSame('start', $service->getStartAction($this->bookWithActivities([])));
  }

  /**
   * Tests that a book whose activities are all closed offers a reread.
   *
   * @covers ::getStartAction
   */
  public function testGetStartActionIsRereadForClosedActivities(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertSame('reread', $service->getStartAction($this->bookWithActivities(['Finished'])));
    $this->assertSame('reread', $service->getStartAction($this->bookWithActivities(['Abandoned'])));
    $this->assertSame(
      'reread',
      $service->getStartAction($this->bookWithActivities(['Finished', 'Abandoned']))
    );
  }

  /**
   * Tests that a book currently being read offers nothing.
   *
   * A second reading activity for the same book would be a duplicate, which
   * is exactly what the route used to allow.
   *
   * @covers ::getStartAction
   */
  public function testGetStartActionIsNullWhileBookIsBeingRead(): void {
    $service = $this->container->get('books_activity.status');

    $this->assertNull($service->getStartAction($this->bookWithActivities(['Reading'])));
    $this->assertNull($service->getStartAction($this->bookWithActivities(['Finished', 'Reading'])));
  }

}

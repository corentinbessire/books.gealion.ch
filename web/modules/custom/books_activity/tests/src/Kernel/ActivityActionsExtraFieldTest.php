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
 * Kernel tests for the reading-log action extra field.
 *
 * The buttons must only be offered for activities that can actually take the
 * transition, and only to users allowed to make it.
 *
 * @group books_activity
 * @coversDefaultClass \Drupal\books_activity\Plugin\ExtraField\Display\ActivityActions
 */
class ActivityActionsExtraFieldTest extends KernelTestBase {

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
    'extra_field',
    'extra_field_plus',
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

    NodeType::create(['type' => 'activity', 'name' => 'Activity'])->save();
    NodeType::create(['type' => 'book', 'name' => 'Book'])->save();

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
      'field_name' => 'field_isbn',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_isbn',
      'entity_type' => 'node',
      'bundle' => 'book',
      'label' => 'ISBN',
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
   * Builds an activity in a given status.
   *
   * @param string $statusName
   *   Status name.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved activity.
   */
  protected function activity(string $statusName): Node {
    $activity = Node::create([
      'type' => 'activity',
      'title' => 'Some book',
      'field_status' => ['target_id' => $this->status[$statusName]],
    ]);
    $activity->save();
    return $activity;
  }

  /**
   * Builds the extra field for a node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The entity being rendered.
   * @param string $pluginId
   *   The extra field plugin id.
   *
   * @return array<string, mixed>
   *   The plugin's render array.
   */
  protected function build(Node $node, string $pluginId): array {
    $plugin = $this->container->get('plugin.manager.extra_field_plus_display')
      ->createInstance($pluginId);
    return $plugin->view($node);
  }

  /**
   * Tests that a reading activity offers both transitions.
   */
  public function testReadingActivityGetsBothActions(): void {
    $this->setUpCurrentUser([], ['edit any activity content']);
    $activity = $this->activity('Reading');

    $build = $this->build($activity, 'activity_actions');

    $this->assertSame('activity_actions', $build['#theme']);
    $this->assertStringContainsString('/activity/' . $activity->id() . '/finish', $build['#finish_url']);
    $this->assertStringContainsString('/activity/' . $activity->id() . '/abandon', $build['#abandon_url']);
  }

  /**
   * Tests that a closed activity offers no transitions.
   */
  public function testClosedActivityGetsNoActions(): void {
    $this->setUpCurrentUser([], ['edit any activity content']);

    $this->assertSame([], $this->build($this->activity('Finished'), 'activity_actions'));
    $this->assertSame([], $this->build($this->activity('Abandoned'), 'activity_actions'));
  }

  /**
   * Tests that a user without the permission is offered no transitions.
   */
  public function testUserWithoutPermissionGetsNoActions(): void {
    $this->setUpCurrentUser([], ['access content']);
    $activity = $this->activity('Reading');

    $this->assertSame([], $this->build($activity, 'activity_actions'));
  }

  /**
   * Creates a book with activities in the given statuses.
   *
   * @param string[] $statusNames
   *   Statuses to attach, may be empty.
   * @param string|null $isbn
   *   ISBN to give the book, or NULL for none.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved book.
   */
  protected function book(array $statusNames = [], ?string $isbn = '9780765326379'): Node {
    $values = ['type' => 'book', 'title' => 'Oathbringer'];
    if ($isbn !== NULL) {
      $values['field_isbn'] = $isbn;
    }
    $book = Node::create($values);
    $book->save();

    foreach ($statusNames as $statusName) {
      Node::create([
        'type' => 'activity',
        'title' => 'Oathbringer',
        'field_book' => ['target_id' => $book->id()],
        'field_status' => ['target_id' => $this->status[$statusName]],
      ])->save();
    }

    return $book;
  }

  /**
   * Tests that an unread book offers the start action.
   */
  public function testUnreadBookOffersStart(): void {
    $this->setUpCurrentUser([], ['create activity content']);

    $build = $this->build($this->book(), 'book_start_action');

    $this->assertSame('book_start_action', $build['#theme']);
    $this->assertSame('start', $build['#action']);
    $this->assertStringContainsString('/activity/start/9780765326379', $build['#url']);
  }

  /**
   * Tests that a previously finished book offers a reread.
   */
  public function testFinishedBookOffersReread(): void {
    $this->setUpCurrentUser([], ['create activity content']);

    $this->assertSame('reread', $this->build($this->book(['Finished']), 'book_start_action')['#action']);
    $this->assertSame('reread', $this->build($this->book(['Abandoned']), 'book_start_action')['#action']);
  }

  /**
   * Tests that a book currently being read offers nothing.
   */
  public function testBookBeingReadOffersNothing(): void {
    $this->setUpCurrentUser([], ['create activity content']);

    $this->assertSame([], $this->build($this->book(['Reading']), 'book_start_action'));
  }

  /**
   * Tests that a user without the permission is offered nothing.
   */
  public function testBookOffersNothingWithoutPermission(): void {
    $this->setUpCurrentUser([], ['access content']);

    $this->assertSame([], $this->build($this->book(), 'book_start_action'));
  }

  /**
   * Tests that a book with no ISBN offers nothing.
   *
   * The route is keyed by ISBN, so there is nothing to link to.
   */
  public function testBookWithoutIsbnOffersNothing(): void {
    $this->setUpCurrentUser([], ['create activity content']);

    $this->assertSame([], $this->build($this->book([], NULL), 'book_start_action'));
  }

}

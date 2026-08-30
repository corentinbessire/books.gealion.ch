<?php

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
    'books_book_managment',
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

}

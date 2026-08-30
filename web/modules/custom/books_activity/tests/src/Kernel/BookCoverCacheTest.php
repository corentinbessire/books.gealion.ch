<?php

namespace Drupal\Tests\books_activity\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for the cover extra field's cache metadata.
 *
 * The cover is reached through the activity's book, so the rendered result
 * depends on an entity the render pipeline never sees. Without the book's
 * cache tags a cached activity keeps its stale cover — and a cached "no
 * cover" result never notices the book gaining one.
 *
 * @group books_activity
 * @coversDefaultClass \Drupal\books_activity\Plugin\ExtraField\Display\BookCover
 */
class BookCoverCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'taxonomy',
    'field',
    'text',
    'file',
    'image',
    'media',
    'user',
    'isbn',
    'extra_field',
    'extra_field_plus',
    'books_catalog',
    'books_activity',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'field']);

    NodeType::create(['type' => 'activity', 'name' => 'Activity'])->save();
    NodeType::create(['type' => 'book', 'name' => 'Book'])->save();

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
      'field_name' => 'field_cover',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cover',
      'entity_type' => 'node',
      'bundle' => 'book',
      'label' => 'Cover',
    ])->save();
  }

  /**
   * Builds the cover extra field for an activity.
   *
   * The plugin reads its display settings from the entity, so it needs its
   * entity and view mode set the way the render pipeline would.
   *
   * @param \Drupal\node\Entity\Node $activity
   *   The activity being rendered.
   *
   * @return array<string, mixed>
   *   The plugin's render array.
   */
  protected function buildCover(Node $activity): array {
    $plugin = $this->container->get('plugin.manager.extra_field_plus_display')
      ->createInstance('book_cover');
    $plugin->setEntity($activity);
    $plugin->setViewMode('default');
    return $plugin->view($activity);
  }

  /**
   * Tests that a book with no cover still bubbles the book's cache tags.
   *
   * This is the path that matters most: the plugin returns nothing, so
   * without explicit metadata the empty result is cached against the
   * activity alone and never notices the book gaining a cover.
   *
   * @covers ::view
   */
  public function testEmptyResultCarriesTheBookCacheTags(): void {
    $book = Node::create(['type' => 'book', 'title' => 'Coverless']);
    $book->save();
    $activity = Node::create([
      'type' => 'activity',
      'title' => 'Coverless',
      'field_book' => ['target_id' => $book->id()],
    ]);
    $activity->save();

    $build = $this->buildCover($activity);

    $tags = $build['#cache']['tags'] ?? [];
    $this->assertContains('node:' . $book->id(), $tags,
      'The book that owns the cover must be a cache dependency.');
    $this->assertContains('node:' . $activity->id(), $tags,
      'The activity being rendered must be a cache dependency.');
  }

  /**
   * Tests that an activity with no book at all still carries its own tags.
   *
   * @covers ::view
   */
  public function testMissingBookStillCarriesActivityCacheTags(): void {
    $activity = Node::create(['type' => 'activity', 'title' => 'Bookless']);
    $activity->save();

    $build = $this->buildCover($activity);

    $this->assertContains('node:' . $activity->id(), $build['#cache']['tags'] ?? []);
  }

}

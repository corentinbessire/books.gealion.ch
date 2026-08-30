<?php

declare(strict_types=1);

namespace Drupal\Tests\books_hardcover\Kernel\Services;

use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Kernel tests for the Hardcover sync queueing service.
 *
 * Replaces the queueBooksForSync coverage that lived on the catalog's book
 * service before the queueing concern moved here.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Services\BookSyncService
 */
class BookSyncServiceKernelTest extends KernelTestBase {

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
    'user',
    'isbn',
    'books_catalog',
    'books_cover',
    'books_hardcover',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\books_hardcover\Services\BookSyncService
   */
  protected $sync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'field']);

    NodeType::create(['type' => 'book', 'name' => 'Book'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

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

    $this->sync = $this->container->get('books_hardcover.sync');
  }

  /**
   * Counts items currently in the sync queue.
   *
   * @return int
   *   Number of queued items.
   */
  protected function queued(): int {
    return (int) $this->container->get('queue')
      ->get(BookSyncService::QUEUE)->numberOfItems();
  }

  /**
   * Tests that a book with an ISBN is queued.
   *
   * @covers ::queueBooksForSync
   */
  public function testQueuesBookWithIsbn(): void {
    $book = Node::create(['type' => 'book', 'title' => 'A', 'field_isbn' => '9780765326379']);
    $book->save();

    $this->assertSame(1, $this->sync->queueBooksForSync([$book->id()]));
    $this->assertSame(1, $this->queued());
  }

  /**
   * Tests that a book with no ISBN is skipped.
   *
   * The worker is keyed by ISBN, so an item without one could never resolve.
   *
   * @covers ::queueBooksForSync
   */
  public function testSkipsBookWithoutIsbn(): void {
    $book = Node::create(['type' => 'book', 'title' => 'B']);
    $book->save();

    $this->assertSame(0, $this->sync->queueBooksForSync([$book->id()]));
    $this->assertSame(0, $this->queued());
  }

  /**
   * Tests that a node of another bundle is skipped rather than fataling.
   *
   * @covers ::queueBooksForSync
   */
  public function testSkipsNonBookNodes(): void {
    $page = Node::create(['type' => 'page', 'title' => 'Not a book']);
    $page->save();

    $this->assertSame(0, $this->sync->queueBooksForSync([$page->id()]));
    $this->assertSame(0, $this->queued());
  }

  /**
   * Tests the single-book enqueue used by the add-book form.
   *
   * @covers ::queueBook
   */
  public function testQueueBookEnqueuesTheExpectedPayload(): void {
    $this->sync->queueBook(42, '9780765326379');

    $item = $this->container->get('queue')->get(BookSyncService::QUEUE)->claimItem();
    $this->assertIsObject($item, 'An item should have been queued.');
    $this->assertSame(
      ['nid' => 42, 'isbn' => '9780765326379', 'only_fill_gaps' => TRUE],
      $item->data
    );
  }

}

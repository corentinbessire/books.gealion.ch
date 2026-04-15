<?php

namespace Drupal\Tests\books_book_managment\Kernel\Services;

use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel tests for BooksUtilsService.
 *
 * @group books_book_managment
 * @coversDefaultClass \Drupal\books_book_managment\Services\BooksUtilsService
 */
class BooksUtilsServiceKernelTest extends KernelTestBase {

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
    'books_book_managment',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\books_book_managment\Services\BooksUtilsService
   */
  protected $booksUtilsService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'node', 'taxonomy', 'field']);

    // Create the 'book' content type.
    NodeType::create([
      'type' => 'book',
      'name' => 'Book',
    ])->save();

    // Create field_isbn on book content type.
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

    // Create field_cover on book content type.
    FieldStorageConfig::create([
      'field_name' => 'field_cover',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_cover',
      'entity_type' => 'node',
      'bundle' => 'book',
      'label' => 'Cover',
    ])->save();

    // Create vocabularies.
    Vocabulary::create(['vid' => 'publisher', 'name' => 'Publisher'])->save();
    Vocabulary::create(['vid' => 'author', 'name' => 'Author'])->save();

    $this->booksUtilsService = $this->container->get('books.books_utils');
  }

  /**
   * Tests service instantiation from the container.
   */
  public function testServiceInstantiation(): void {
    $this->assertInstanceOf(BooksUtilsService::class, $this->booksUtilsService);
  }

  /**
   * Tests getBook() creates a new node when ISBN not found.
   *
   * @covers ::getBook
   */
  public function testGetBookCreatesNew(): void {
    $book = $this->booksUtilsService->getBook('9780000000000');
    $this->assertNotNull($book);
    $this->assertTrue($book->isNew());
  }

  /**
   * Tests getBook() returns NULL when ISBN not found and create=FALSE.
   *
   * @covers ::getBook
   */
  public function testGetBookReturnsNullWhenNotCreating(): void {
    $result = $this->booksUtilsService->getBook('9780000000000', FALSE);
    $this->assertNull($result);
  }

  /**
   * Tests getTermByName() creates and loads terms.
   *
   * @covers ::getTermByName
   */
  public function testGetTermByNameCreatesAndLoads(): void {
    // First call should create the term.
    $term = $this->booksUtilsService->getTermByName('Penguin Books', 'publisher');
    $this->assertNotNull($term);
    $this->assertEquals('Penguin Books', $term->label());

    // Second call should load the same term.
    $termAgain = $this->booksUtilsService->getTermByName('Penguin Books', 'publisher');
    $this->assertEquals($term->id(), $termAgain->id());
  }

  /**
   * Tests getTermByName() returns NULL for empty name.
   *
   * @covers ::getTermByName
   */
  public function testGetTermByNameEmptyReturnsNull(): void {
    $this->assertNull($this->booksUtilsService->getTermByName('', 'publisher'));
  }

  /**
   * Tests getBooksMissingCover() returns node IDs.
   *
   * @covers ::getBooksMissingCover
   */
  public function testGetBooksMissingCover(): void {
    // Create a book node without cover.
    $node = Node::create([
      'type' => 'book',
      'title' => 'Test Book',
    ]);
    $node->save();

    $result = $this->booksUtilsService->getBooksMissingCover();
    $this->assertContains($node->id(), $result);
  }

}

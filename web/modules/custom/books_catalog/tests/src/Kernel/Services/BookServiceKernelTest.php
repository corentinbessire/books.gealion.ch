<?php

namespace Drupal\Tests\books_catalog\Kernel\Services;

use Drupal\books_catalog\Services\BookService;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Kernel tests for BookService.
 *
 * @group books_catalog
 * @coversDefaultClass \Drupal\books_catalog\Services\BookService
 */
class BookServiceKernelTest extends KernelTestBase {

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
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\books_catalog\Services\BookService
   */
  protected $bookService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
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

    // Create field_pages on book content type.
    FieldStorageConfig::create([
      'field_name' => 'field_pages',
      'entity_type' => 'node',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_pages',
      'entity_type' => 'node',
      'bundle' => 'book',
      'label' => 'Pages',
    ])->save();

    // Create field_excerpt on book content type.
    FieldStorageConfig::create([
      'field_name' => 'field_excerpt',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_excerpt',
      'entity_type' => 'node',
      'bundle' => 'book',
      'label' => 'Excerpt',
    ])->save();

    // Create vocabularies.
    Vocabulary::create(['vid' => 'publisher', 'name' => 'Publisher'])->save();
    Vocabulary::create(['vid' => 'author', 'name' => 'Author'])->save();

    // Vocabularies and fields the Hardcover mapping needs.
    foreach (['serie', 'genre', 'mood'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => ucfirst($vid)])->save();
    }

    $termFields = [
      'field_serie' => ['cardinality' => 1, 'vid' => 'serie'],
      'field_genres' => ['cardinality' => -1, 'vid' => 'genre'],
      'field_moods' => ['cardinality' => -1, 'vid' => 'mood'],
    ];
    foreach ($termFields as $fieldName => $info) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => $info['cardinality'],
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'book',
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [$info['vid'] => $info['vid']],
            'auto_create' => TRUE,
          ],
        ],
      ])->save();
    }

    FieldStorageConfig::create([
      'field_name' => 'field_serie_position',
      'entity_type' => 'node',
      'type' => 'decimal',
      'cardinality' => 1,
      'settings' => ['precision' => 6, 'scale' => 2],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_serie_position',
      'entity_type' => 'node',
      'bundle' => 'book',
    ])->save();

    $this->bookService = $this->container->get('books_catalog.books');
  }

  /**
   * Tests service instantiation from the container.
   */
  public function testServiceInstantiation(): void {
    $this->assertInstanceOf(BookService::class, $this->bookService);
  }

  /**
   * Tests getBook() creates a new node when ISBN not found.
   *
   * @covers ::getBook
   */
  public function testGetBookCreatesNew(): void {
    $book = $this->bookService->getBook('9780000000000');
    $this->assertNotNull($book);
    $this->assertTrue($book->isNew());
  }

  /**
   * Tests getBook() returns NULL when ISBN not found and create=FALSE.
   *
   * @covers ::getBook
   */
  public function testGetBookReturnsNullWhenNotCreating(): void {
    $result = $this->bookService->getBook('9780000000000', FALSE);
    $this->assertNull($result);
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

    $result = $this->bookService->getBooksMissingCover();
    $this->assertContains($node->id(), $result);
  }

  /**
   * Tests that series, genres and moods are saved as taxonomy terms.
   *
   * @covers ::saveBookData
   */
  public function testSaveBookDataCreatesSeriesGenresAndMoods(): void {
    $book = $this->bookService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_serie' => 'The Stormlight Archive',
      'field_serie_position' => 3.0,
      'field_genres' => ['Fantasy', 'Adventure'],
      'field_moods' => ['Adventurous', 'Emotional', 'Tense'],
    ]);

    // Decimal field values are only normalized to their stored string
    // representation by the database once the entity is reloaded from
    // storage; the in-memory entity returned by saveBookData() still holds
    // the raw PHP float that was assigned.
    $reloaded = $this->reloadBook($book);

    $this->assertSame('The Stormlight Archive', $book->get('field_serie')->entity->label());
    $this->assertSame('3.00', $reloaded->get('field_serie_position')->value);
    $this->assertCount(2, $book->get('field_genres'));
    $this->assertCount(3, $book->get('field_moods'));
  }

  /**
   * Tests that a fractional series position survives the round trip.
   *
   * @covers ::saveBookData
   */
  public function testSaveBookDataStoresFractionalPosition(): void {
    $book = $this->bookService->saveBookData('9780765326386', [
      'title' => 'Edgedancer',
      'field_serie_position' => 2.5,
    ]);

    $reloaded = $this->reloadBook($book);
    $this->assertSame('2.50', $reloaded->get('field_serie_position')->value);
  }

  /**
   * Tests that gap-fill mode leaves existing values alone.
   *
   * @covers ::saveBookData
   */
  public function testGapFillDoesNotOverwritePopulatedFields(): void {
    // field_isbn must be included, as it always is in the real formatted
    // data from HardcoverService::formatBookData(): getBook() only persists
    // field_isbn from $data, so without it the second call below would not
    // find this node by ISBN and would create a new one instead of gap
    // filling it.
    $this->bookService->saveBookData('9780765326379', [
      'title' => 'My Corrected Title',
      'field_isbn' => '9780765326379',
      'field_pages' => 1243,
    ]);

    $book = $this->bookService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_pages' => 999,
      'field_excerpt' => 'Filled from Hardcover.',
    ], TRUE);

    $this->assertSame('My Corrected Title', $book->getTitle());
    $this->assertSame('1243', (string) $book->get('field_pages')->value);
    $this->assertSame('Filled from Hardcover.', $book->get('field_excerpt')->value);
  }

  /**
   * Tests that rescanning a known ISBN keeps the title already on the node.
   *
   * This is the exact call AddBookForm makes when Hardcover is unreachable or
   * rate limited: a stub save carrying nothing but the ISBN. Without gap-fill
   * mode it would rename an existing book to its bare barcode.
   *
   * @covers ::saveBookData
   */
  public function testStubSaveDoesNotRenameAnExistingBook(): void {
    $isbn = '9780765326379';
    $this->bookService->saveBookData($isbn, [
      'title' => 'Oathbringer',
      'field_isbn' => $isbn,
    ]);

    $book = $this->bookService->saveBookData($isbn, ['field_isbn' => $isbn], TRUE);

    $this->assertSame('Oathbringer', $book->getTitle());
    $this->assertSame('Oathbringer', $this->reloadBook($book)->getTitle());
  }

  /**
   * Tests that every book node is returned for the backfill.
   *
   * @covers ::getAllBooks
   */
  public function testGetAllBooksReturnsEveryBook(): void {
    $this->bookService->saveBookData('9780765326379', ['title' => 'One']);
    $this->bookService->saveBookData('9780765326386', ['title' => 'Two']);

    $this->assertCount(2, $this->bookService->getAllBooks());
  }

  /**
   * Tests that a legitimate zero value is persisted, not dropped.
   *
   * @covers ::saveBookData
   */
  public function testSaveBookDataPersistsZeroValues(): void {
    $book = $this->bookService->saveBookData('9780765326379', [
      'title' => 'Zeroth',
      'field_isbn' => '9780765326379',
      'field_pages' => 0,
      'field_serie_position' => 0.0,
    ]);

    $reloaded = $this->reloadBook($book);
    $this->assertSame('0', (string) $reloaded->get('field_pages')->value);
    $this->assertSame('0.00', $reloaded->get('field_serie_position')->value);
  }

  /**
   * Tests that gap-fill mode does not overwrite a populated value with zero.
   *
   * @covers ::saveBookData
   */
  public function testGapFillDoesNotOverwritePopulatedFieldWithZero(): void {
    $this->bookService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_isbn' => '9780765326379',
      'field_serie_position' => 3.0,
    ]);

    $book = $this->bookService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_serie_position' => 0.0,
    ], TRUE);

    $reloaded = $this->reloadBook($book);
    $this->assertSame('3.00', $reloaded->get('field_serie_position')->value);
  }

  /**
   * Reloads a book node from storage.
   *
   * Decimal field values are stored as strings by the database and are
   * only reflected as such once the entity is reloaded; the in-memory
   * entity still holds whatever PHP type was assigned to it.
   *
   * @param \Drupal\node\NodeInterface $book
   *   The book node to reload.
   *
   * @return \Drupal\node\NodeInterface
   *   The reloaded book node.
   */
  protected function reloadBook(NodeInterface $book): NodeInterface {
    return $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->loadUnchanged($book->id());
  }

}

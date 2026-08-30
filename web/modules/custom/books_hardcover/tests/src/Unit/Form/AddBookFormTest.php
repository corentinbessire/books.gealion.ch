<?php

namespace Drupal\Tests\books_hardcover\Unit\Form;

use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\books_hardcover\Form\AddBookForm;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_cover\Services\CoverDownloadService;
use Drupal\books_hardcover\Services\HardcoverService;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\isbn\IsbnToolsServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

/**
 * Unit tests for AddBookForm.
 *
 * @group books_hardcover
 * @coversDefaultClass \Drupal\books_hardcover\Form\AddBookForm
 */
class AddBookFormTest extends UnitTestCase {

  /**
   * The form under test.
   *
   * @var \Drupal\books_hardcover\Form\AddBookForm
   */
  protected $form;

  /**
   * The Hardcover service mock.
   *
   * @var \Drupal\books_hardcover\Services\HardcoverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $hardcoverService;

  /**
   * The cover download service mock.
   *
   * @var \Drupal\books_cover\Services\CoverDownloadService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $coverDownloadService;

  /**
   * The books utils service mock.
   *
   * @var \Drupal\books_catalog\Services\BookService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $bookService;

  /**
   * The queue factory mock.
   *
   * @var \Drupal\books_hardcover\Services\BookSyncService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $bookSync;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $isbnService = $this->createMock(IsbnToolsServiceInterface::class);
    $this->hardcoverService = $this->createMock(HardcoverService::class);
    $this->coverDownloadService = $this->createMock(CoverDownloadService::class);
    $this->bookService = $this->createMock(BookService::class);
    $this->bookSync = $this->createMock(BookSyncService::class);

    $this->form = new AddBookForm(
      $isbnService,
      $this->hardcoverService,
      $this->coverDownloadService,
      $this->bookService,
      $this->bookSync
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->createMock(MessengerInterface::class));
  }

  /**
   * Tests getFormId() returns correct ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('add_book_form', $this->form->getFormId());
  }

  /**
   * Tests that a rate limit still saves a stub and queues a sync.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormQueuesSyncOnRateLimit(): void {
    $isbn = '9780765326379';

    $this->hardcoverService->method('getFormattedBookData')
      ->with($isbn)
      ->willThrowException(new HardcoverRateLimitException(30));

    $book = $this->createMock(NodeInterface::class);
    $book->method('id')->willReturn(42);

    $this->bookService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, ['field_isbn' => $isbn], TRUE)
      ->willReturn($book);

    $this->bookSync->expects($this->once())
      ->method('queueBook')
      ->with(42, $isbn);

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('isbn')->willReturn($isbn);
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('entity.node.canonical', ['node' => 42]);

    $this->form->submitForm($form, $formState);
  }

  /**
   * Tests that a connection failure still saves a stub and queues a sync.
   *
   * A ConnectException is not a RequestException, so it used to travel all the
   * way out of submitForm() and white-screen the page, losing the barcode the
   * user had just scanned.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormQueuesSyncOnConnectionFailure(): void {
    $isbn = '9780765326379';

    $this->hardcoverService->method('getFormattedBookData')
      ->with($isbn)
      ->willThrowException(new ConnectException(
        'cURL error 6: Could not resolve host: api.hardcover.app',
        new Request('POST', HardcoverService::API_ENDPOINT)
      ));

    $book = $this->createMock(NodeInterface::class);
    $book->method('id')->willReturn(11);

    $this->bookService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, ['field_isbn' => $isbn], TRUE)
      ->willReturn($book);

    $this->bookSync->expects($this->once())
      ->method('queueBook')
      ->with(11, $isbn);

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('isbn')->willReturn($isbn);
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('entity.node.canonical', ['node' => 11]);

    $this->form->submitForm($form, $formState);
  }

  /**
   * Tests that an ISBN Hardcover has no data for still creates a stub node.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormCreatesStubWhenNoData(): void {
    $isbn = '9780000000000';

    $this->hardcoverService->method('getFormattedBookData')
      ->with($isbn)
      ->willReturn(NULL);

    $book = $this->createMock(NodeInterface::class);
    $book->method('id')->willReturn(7);

    $this->bookService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, ['field_isbn' => $isbn], TRUE)
      ->willReturn($book);

    $this->bookSync->expects($this->never())->method('queueBook');

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('isbn')->willReturn($isbn);
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('entity.node.canonical', ['node' => 7]);

    $this->form->submitForm($form, $formState);
  }

  /**
   * Tests that a successful lookup downloads the cover and saves the book.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormSavesFormattedDataOnSuccess(): void {
    $isbn = '9780765326386';
    $bookData = [
      'title' => 'Edgedancer',
      'cover_url' => 'https://example.com/cover.jpg',
    ];

    $this->hardcoverService->method('getFormattedBookData')
      ->with($isbn)
      ->willReturn($bookData);

    $cover = $this->createMock(EntityInterface::class);
    $this->coverDownloadService->expects($this->once())
      ->method('downloadBookCover')
      ->with($isbn, $bookData['cover_url'])
      ->willReturn($cover);

    $book = $this->createMock(NodeInterface::class);
    $book->method('id')->willReturn(99);

    $this->bookService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, $bookData + ['field_cover' => $cover])
      ->willReturn($book);

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('isbn')->willReturn($isbn);
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('entity.node.canonical', ['node' => 99]);

    $this->form->submitForm($form, $formState);
  }

}

<?php

namespace Drupal\Tests\books_book_managment\Unit\Form;

use Drupal\books_book_managment\Exception\HardcoverRateLimitException;
use Drupal\books_book_managment\Form\AddBookForm;
use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\books_book_managment\Services\CoverDownloadService;
use Drupal\books_book_managment\Services\HardcoverService;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\isbn\IsbnToolsServiceInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for AddBookForm.
 *
 * @group books_book_managment
 * @coversDefaultClass \Drupal\books_book_managment\Form\AddBookForm
 */
class AddBookFormTest extends UnitTestCase {

  /**
   * The form under test.
   *
   * @var \Drupal\books_book_managment\Form\AddBookForm
   */
  protected $form;

  /**
   * The Hardcover service mock.
   *
   * @var \Drupal\books_book_managment\Services\HardcoverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $hardcoverService;

  /**
   * The cover download service mock.
   *
   * @var \Drupal\books_book_managment\Services\CoverDownloadService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $coverDownloadService;

  /**
   * The books utils service mock.
   *
   * @var \Drupal\books_book_managment\Services\BooksUtilsService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $booksUtilsService;

  /**
   * The queue factory mock.
   *
   * @var \Drupal\Core\Queue\QueueFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queueFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $isbnService = $this->createMock(IsbnToolsServiceInterface::class);
    $this->hardcoverService = $this->createMock(HardcoverService::class);
    $this->coverDownloadService = $this->createMock(CoverDownloadService::class);
    $this->booksUtilsService = $this->createMock(BooksUtilsService::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);

    $this->form = new AddBookForm(
      $isbnService,
      $this->hardcoverService,
      $this->coverDownloadService,
      $this->booksUtilsService,
      $this->queueFactory
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

    $book = $this->createMock(EntityInterface::class);
    $book->method('id')->willReturn(42);

    $this->booksUtilsService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, ['field_isbn' => $isbn])
      ->willReturn($book);

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())
      ->method('createItem')
      ->with([
        'nid' => 42,
        'isbn' => $isbn,
        'only_fill_gaps' => TRUE,
      ]);
    $this->queueFactory->expects($this->once())
      ->method('get')
      ->with('hardcover_book_sync')
      ->willReturn($queue);

    $form = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->with('isbn')->willReturn($isbn);
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('entity.node.canonical', ['node' => 42]);

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

    $book = $this->createMock(EntityInterface::class);
    $book->method('id')->willReturn(7);

    $this->booksUtilsService->expects($this->once())
      ->method('saveBookData')
      ->with($isbn, ['field_isbn' => $isbn])
      ->willReturn($book);

    $this->queueFactory->expects($this->never())->method('get');

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

    $book = $this->createMock(EntityInterface::class);
    $book->method('id')->willReturn(99);

    $this->booksUtilsService->expects($this->once())
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

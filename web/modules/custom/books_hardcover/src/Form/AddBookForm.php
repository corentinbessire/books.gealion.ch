<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_cover\Services\CoverDownloadService;
use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\books_hardcover\Services\HardcoverService;
use Drupal\isbn\IsbnToolsServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Custom Form to add book by ISBN.
 */
class AddBookForm extends FormBase {

  /**
   * Constructs a AddBookForm object.
   *
   * @param \Drupal\isbn\IsbnToolsServiceInterface $isbnToolsService
   *   ISBN Tools Service.
   * @param \Drupal\books_hardcover\Services\HardcoverService $hardcoverService
   *   Hardcover data service.
   * @param \Drupal\books_cover\Services\CoverDownloadService $coverDownloadService
   *   Cover Downloader Service.
   * @param \Drupal\books_catalog\Services\BookService $bookService
   *   Book Utilities Service.
   * @param \Drupal\books_hardcover\Services\BookSyncService $bookSync
   *   Enqueues the book when the API is rate limited.
   */
  public function __construct(
    protected IsbnToolsServiceInterface $isbnToolsService,
    protected HardcoverService $hardcoverService,
    protected CoverDownloadService $coverDownloadService,
    protected BookService $bookService,
    protected BookSyncService $bookSync,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('isbn.isbn_service'),
      $container->get('books_hardcover.client'),
      $container->get('books_cover.downloader'),
      $container->get('books_catalog.books'),
      $container->get('books_hardcover.sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_book_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Scope the Alpine "isbnScanner" component (registered in the theme bundle)
    // to this form so the Scan button and overlay only live on /add-book.
    $form['#attributes']['x-data'] = 'isbnScanner';

    $form['isbn'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ISBN'),
      '#required' => TRUE,
      // Let the scanner write the decoded barcode into this field.
      '#attributes' => ['x-ref' => 'isbn'],
    ];

    // The markup lives in a template so the theme can override it and the
    // Twig gets normal tooling; a plain #markup would strip the Alpine
    // attributes the scanner needs.
    $form['scanner'] = [
      '#theme' => 'isbn_scanner',
      '#scan_label' => $this->t('Scan barcode'),
      '#hint' => $this->t("Point the camera at the book's barcode"),
      '#close_label' => $this->t('Close'),
      '#starting_label' => $this->t('Starting camera…'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add book'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /**
     * @var \Drupal\isbn\IsbnToolsService $isbnValidator
     */
    $isbnValidator = $this->isbnToolsService;
    if (!$isbnValidator->isValidIsbn($form_state->getValue('isbn'))) {
      $form_state->setError($form['isbn'], 'This is not a valid ISBN number.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $isbn = $form_state->getValue('isbn');

    try {
      $bookData = $this->hardcoverService->getFormattedBookData($isbn);
    }
    catch (HardcoverRateLimitException) {
      $this->saveStub(
        $isbn,
        $form_state,
        $this->t('Hardcover is rate limited right now. The book was saved and will be filled in automatically.'),
        TRUE
      );
      return;
    }
    catch (\Exception) {
      // Anything else — a dropped connection, a malformed response — must not
      // white-screen the form: a scanned barcode is never allowed to be lost.
      // The book is stubbed out and the queue fills it in later.
      $this->saveStub(
        $isbn,
        $form_state,
        $this->t('Hardcover could not be reached. The book was saved and will be filled in automatically.'),
        TRUE
      );
      return;
    }

    if ($bookData === NULL) {
      $this->saveStub(
        $isbn,
        $form_state,
        $this->t('Hardcover has no data for this ISBN. The book was created — please fill in the details.'),
        FALSE
      );
      return;
    }

    $cover = $this->coverDownloadService->downloadBookCover($isbn, $bookData['cover_url'] ?? NULL);
    if ($cover) {
      $bookData['field_cover'] = $cover;
    }

    $book = $this->bookService->saveBookData($isbn, $bookData);
    $this->messenger()->addStatus($this->t('Book has been created'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
  }

  /**
   * Saves a book holding nothing but its ISBN and redirects to it.
   *
   * Always gap-filling: getBook() resolves an existing node by its stored
   * ISBN, so rescanning a book already in the library must not overwrite its
   * title with the bare barcode.
   *
   * @param string $isbn
   *   The scanned ISBN.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, redirected to the saved node.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   Warning shown to the user.
   * @param bool $queue
   *   Whether to queue a Hardcover sync, which only makes sense when the
   *   lookup failed rather than when Hardcover simply has no record.
   */
  protected function saveStub(string $isbn, FormStateInterface $form_state, TranslatableMarkup $message, bool $queue): void {
    $book = $this->bookService->saveBookData($isbn, ['field_isbn' => $isbn], TRUE);

    if ($queue) {
      $this->bookSync->queueBook($book->id(), $isbn);
    }

    $this->messenger()->addWarning($message);
    $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
  }

}

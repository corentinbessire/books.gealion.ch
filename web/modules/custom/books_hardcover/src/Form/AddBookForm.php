<?php

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

    // Camera scan button + fullscreen overlay. Rendered via inline_template so
    // the Alpine attributes survive (a plain #markup would be XSS-filtered).
    $form['scanner'] = [
      '#type' => 'inline_template',
      '#template' => <<<'TWIG'
        <button type="button" x-on:click="start()"
          class="mt-2 inline-flex items-center gap-2 rounded-md border border-burgundy bg-cream px-3.5 py-2.5 text-sm font-semibold text-burgundy shadow-sm hover:bg-parchment focus-visible:outline focus-visible:outline-offset-2 focus-visible:outline-burgundy">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
          </svg>
          {{ scan_label }}
        </button>

        <template x-teleport="body">
          <div x-show="open" x-cloak style="display:none" class="fixed inset-0 z-50 bg-black">
            <!-- Camera preview fills the whole screen. -->
            <video x-ref="video" playsinline muted class="absolute inset-0 h-full w-full object-cover"></video>

            <!-- Aiming frame, dimming everything around it. -->
            <div class="pointer-events-none absolute inset-x-8 top-1/2 h-36 -translate-y-1/2 rounded-xl border-2 border-white/80 shadow-[0_0_0_100vmax_rgba(0,0,0,0.45)]"></div>

            <!-- Floating close button (respects the notch / safe areas). -->
            <button type="button" x-on:click="stop()" aria-label="{{ close_label }}"
              class="absolute z-10 flex h-12 w-12 items-center justify-center rounded-full bg-black/50 text-white backdrop-blur-sm transition hover:bg-black/70 active:scale-95"
              style="top:max(1rem,env(safe-area-inset-top));right:max(1rem,env(safe-area-inset-right));">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>

            <!-- Status line at the bottom; only one shows at a time. -->
            <div class="absolute inset-x-0 bottom-0 p-6 text-center text-sm" style="padding-bottom:max(1.5rem,env(safe-area-inset-bottom))">
              <p x-show="starting" class="text-white/80">{{ starting_label }}</p>
              <p x-show="error" x-text="error" class="text-red-300"></p>
              <p x-show="!starting && !error" class="text-white/90">{{ hint }}</p>
            </div>
          </div>
        </template>
        TWIG,
      '#context' => [
        'scan_label' => $this->t('Scan barcode'),
        'hint' => $this->t("Point the camera at the book's barcode"),
        'close_label' => $this->t('Close'),
        'starting_label' => $this->t('Starting camera…'),
      ],
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

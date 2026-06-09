<?php

namespace Drupal\books_book_managment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\books_book_managment\Services\CoverDownloadService;
use Drupal\books_book_managment\Services\GoogleBooksService;
use Drupal\books_book_managment\Services\OpenLibraryService;
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
   * @param \Drupal\books_book_managment\Services\OpenLibraryService $openLibraryService
   *   Open Library Service.
   * @param \Drupal\books_book_managment\Services\GoogleBooksService $googleBooksService
   *   Google Book Service.
   * @param \Drupal\books_book_managment\Services\CoverDownloadService $coverDownloadService
   *   Cover Downloader Service.
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Book Utilities Service.
   */
  public function __construct(
    protected IsbnToolsServiceInterface $isbnToolsService,
    protected OpenLibraryService $openLibraryService,
    protected GoogleBooksService $googleBooksService,
    protected CoverDownloadService $coverDownloadService,
    protected BooksUtilsService $booksUtilsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('isbn.isbn_service'),
      $container->get('books.open_library'),
      $container->get('books.google_books'),
      $container->get('books.cover_download'),
      $container->get('books.books_utils'),
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
          <div x-show="open" x-cloak style="display:none" class="fixed inset-0 z-50 flex flex-col bg-black/90">
            <div class="flex items-center justify-between p-4 text-white">
              <span class="text-sm">{{ hint }}</span>
              <button type="button" x-on:click="stop()"
                class="rounded-md border border-white/40 px-3 py-1.5 text-sm font-semibold text-white hover:bg-white/10">
                {{ close_label }}
              </button>
            </div>
            <div class="relative flex flex-1 items-center justify-center overflow-hidden">
              <video x-ref="video" playsinline muted class="h-full w-full object-cover"></video>
              <div class="pointer-events-none absolute inset-x-8 top-1/2 h-32 -translate-y-1/2 rounded-lg border-2 border-white/80"></div>
            </div>
            <p x-show="starting" class="p-4 text-center text-sm text-white/70">{{ starting_label }}</p>
            <p x-show="error" x-text="error" class="p-4 text-center text-sm text-red-300"></p>
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
    $olBookData = $this->openLibraryService
      ->getFormattedBookData($isbn) ?? [];

    $gbBookData = $this->googleBooksService
      ->getFormattedBookData($isbn) ?? [];

    $bookData = $this->mergeBookData($gbBookData, $olBookData);

    if ($bookData) {
      $cover = $this->coverDownloadService
        ->downloadBookCover($isbn);
      if ($cover) {
        $bookData['field_cover'] = $cover;
      }
      $book = $this->booksUtilsService
        ->saveBookData($isbn, $bookData);

      $this->messenger()->addStatus($this->t('Book has been created'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
    }
    else {
      $this->messenger()->addWarning($this->t('No data found for given ISBN.'));
    }
  }

  /**
   * Merge Book Data of multiple Source.
   *
   * @param array $array1
   *   Data From Source 1.
   * @param array $array2
   *   Data From Source 2.
   *
   * @return array
   *   merged Data.
   */
  protected function mergeBookData(array $array1, array $array2): array {
    $keys = array_unique(array_merge(array_keys($array1), array_keys($array2)));
    $books_data = [];
    foreach ($keys as $key) {
      $books_data[$key] = $array1[$key] ?? $array2[$key];
    }
    return $books_data;
  }

}

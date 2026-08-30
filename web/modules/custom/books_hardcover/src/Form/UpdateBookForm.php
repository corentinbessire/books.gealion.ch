<?php

namespace Drupal\books_hardcover\Form;

use Drupal\books_catalog\Services\BookService;
use Drupal\books_hardcover\Services\BookSyncService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to batch-update book data from external APIs.
 */
class UpdateBookForm extends FormBase {

  /**
   * Constructs an UpdateBookForm object.
   *
   * @param \Drupal\books_catalog\Services\BookService $bookService
   *   Book Utilities Service.
   * @param \Drupal\books_hardcover\Services\BookSyncService $bookSync
   *   Enqueues books for a Hardcover sync.
   */
  public function __construct(
    protected BookService $bookService,
    protected BookSyncService $bookSync,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('books_catalog.books'),
      $container->get('books_hardcover.sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'update_book_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Queue every book for a Hardcover sync. Only empty fields are filled, so manual corrections are kept. The queue is drained by cron, pausing automatically when the API rate limit is reached.') . '</p>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue all books for sync'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $queued = $this->bookSync->queueBooksForSync($this->bookService->getAllBooks());

    if ($queued === 0) {
      $this->messenger()->addStatus($this->t('No books to queue.'));
      return;
    }

    $this->messenger()->addStatus($this->t('@count book(s) queued for Hardcover sync.', [
      '@count' => $queued,
    ]));
  }

}

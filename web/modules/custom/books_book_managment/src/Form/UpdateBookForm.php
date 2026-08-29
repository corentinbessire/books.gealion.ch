<?php

namespace Drupal\books_book_managment\Form;

use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to batch-update book data from external APIs.
 */
class UpdateBookForm extends FormBase {

  /**
   * Constructs an UpdateBookForm object.
   *
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Book Utilities Service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(
    protected BooksUtilsService $booksUtilsService,
    protected QueueFactory $queueFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('books.books_utils'),
      $container->get('queue'),
      $container->get('entity_type.manager'),
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
    $queued = $this->booksUtilsService->queueBooksForSync($this->booksUtilsService->getAllBooks());

    if ($queued === 0) {
      $this->messenger()->addStatus($this->t('No books to queue.'));
      return;
    }

    $this->messenger()->addStatus($this->t('@count book(s) queued for Hardcover sync.', [
      '@count' => $queued,
    ]));
  }

}

<?php

namespace Drupal\books_book_managment\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;

/**
 * Custom Service to handle various Book related actions.
 */
class BooksUtilsService {

  /**
   * Constructs a BooksUtilsService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory, used to enqueue books for a Hardcover sync.
   */
  public function __construct(
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Saves formatted data into a book node.
   *
   * @param string $isbn
   *   ISBN-13 of the book.
   * @param array<string, mixed> $data
   *   Formatted data keyed by field name.
   * @param bool $onlyFillGaps
   *   When TRUE, only fields that are currently empty are written, so manual
   *   corrections survive a re-sync.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved book node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function saveBookData(string $isbn, array $data, bool $onlyFillGaps = FALSE): NodeInterface {
    $book = $this->getBook($isbn);
    if ($book === NULL) {
      // getBook() only returns NULL when told not to create a node, which
      // never happens here since we call it with its default arguments.
      throw new \RuntimeException(sprintf('Could not load or create a book node for ISBN %s.', $isbn));
    }

    if (!$onlyFillGaps || $book->isNew() || $book->getTitle() === NULL || $book->getTitle() === '') {
      $book->setTitle($data['title'] ?? $isbn);
    }

    $simpleFields = [
      'field_pages',
      'field_isbn',
      'field_release',
      'field_excerpt',
      'field_cover',
      'field_serie_position',
    ];
    foreach ($simpleFields as $field) {
      if (!array_key_exists($field, $data) || $data[$field] === NULL || $data[$field] === '') {
        continue;
      }
      if (!$this->shouldWrite($book, $field, $onlyFillGaps)) {
        continue;
      }
      $book->set($field, $data[$field]);
    }

    if (
      isset($data['field_publisher']) && $data['field_publisher'] !== ''
      && $this->shouldWrite($book, 'field_publisher', $onlyFillGaps)
    ) {
      $book->set('field_publisher', $this->getTermByName($data['field_publisher'], 'publisher'));
    }

    if (
      isset($data['field_serie']) && $data['field_serie'] !== ''
      && $this->shouldWrite($book, 'field_serie', $onlyFillGaps)
    ) {
      $book->set('field_serie', $this->getTermByName($data['field_serie'], 'serie'));
    }

    $multiValue = [
      'field_authors' => 'author',
      'field_genres' => 'genre',
      'field_moods' => 'mood',
    ];
    foreach ($multiValue as $field => $vid) {
      if (empty($data[$field]) || !is_array($data[$field]) || !$this->shouldWrite($book, $field, $onlyFillGaps)) {
        continue;
      }
      $book->set($field, $this->buildTermReferences($data[$field], $vid));
    }

    $book->save();
    return $book;
  }

  /**
   * Decides whether a field should be written.
   *
   * @param \Drupal\node\NodeInterface $book
   *   The book node.
   * @param string $field
   *   The field name.
   * @param bool $onlyFillGaps
   *   Whether gap-fill mode is active.
   *
   * @return bool
   *   TRUE when the field should be written.
   */
  protected function shouldWrite(NodeInterface $book, string $field, bool $onlyFillGaps): bool {
    if (!$book->hasField($field)) {
      return FALSE;
    }
    return !$onlyFillGaps || $book->get($field)->isEmpty();
  }

  /**
   * Upserts terms by name and returns entity reference values.
   *
   * @param array<int, string> $names
   *   Term names.
   * @param string $vid
   *   Vocabulary id.
   *
   * @return array<int, array{target_id: int|string}>
   *   Entity reference field values.
   */
  protected function buildTermReferences(array $names, string $vid): array {
    $values = [];
    foreach ($names as $name) {
      $term = $this->getTermByName($name, $vid);
      if ($term) {
        $values[] = ['target_id' => $term->id()];
      }
    }
    return $values;
  }

  /**
   * Gets the node IDs of every book.
   *
   * @return array<int, int|string>
   *   Array of node IDs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getAllBooks(): array {
    return $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'book')
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Return existing Node Book with given ISBN or create new entity.
   *
   * @param string $isbn
   *   ISBN-13 Value.
   * @param bool $create
   *   Create the Book Node if not existing.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Book node Entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getBook(string $isbn, bool $create = TRUE): ?NodeInterface {
    $books = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['field_isbn' => $isbn]);
    if (!empty($books)) {
      return end($books);
    }
    else {
      if ($create) {
        return $this->entityTypeManager->getStorage('node')
          ->create(['type' => 'book']);
      }
      else {
        return NULL;
      }
    }
  }

  /**
   * Upsert Term given Name and VID.
   *
   * @param string $termName
   *   The Term Name to Upsert.
   * @param string $vid
   *   The Vocabulary id of the Term to Upsert.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The Upserted Entity term.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getTermByName(string $termName, string $vid): ?EntityInterface {
    if (!$termName) {
      return NULL;
    }
    $result = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', $vid)
      ->condition('name', $termName)
      ->accessCheck()
      ->execute();
    if (empty($result)) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vid,
      ]);
      $term->set('name', $termName);
      $term->save();
    }
    else {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')
        ->load(reset($result));
    }
    return $term;
  }

  /**
   * Get an array of NIDs of Book nodes without Cover.
   *
   * @return array
   *   Array of Nids.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getBooksMissingCover(): array {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'book')
      ->notExists('field_cover')
      ->accessCheck()
      ->execute();
    return $nids;
  }

  /**
   * Queues book nodes for a Hardcover sync.
   *
   * @param array<int, int|string> $nids
   *   Node IDs to queue.
   *
   * @return int
   *   Number of items queued.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function queueBooksForSync(array $nids): int {
    $queue = $this->queueFactory->get('hardcover_book_sync');
    $queued = 0;

    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      $isbn = $node->get('field_isbn')->value;
      if (empty($isbn)) {
        continue;
      }
      $queue->createItem([
        'nid' => (int) $node->id(),
        'isbn' => $isbn,
        'only_fill_gaps' => TRUE,
      ]);
      $queued++;
    }

    return $queued;
  }

}

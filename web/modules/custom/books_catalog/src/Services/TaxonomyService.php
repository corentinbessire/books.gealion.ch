<?php

namespace Drupal\books_catalog\Services;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Upserts taxonomy terms by name.
 *
 * Book imports name their authors, publishers, series, genres and moods as
 * plain strings; this turns a name into a term, creating it when it is new.
 */
class TaxonomyService {

  /**
   * Constructs a TaxonomyService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the term with this name in this vocabulary, creating it if absent.
   *
   * @param string $termName
   *   The term name.
   * @param string $vid
   *   The vocabulary id.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The term, or NULL when the name is empty.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getTermByName(string $termName, string $vid): ?TermInterface {
    if (!$termName) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $result = $storage->getQuery()
      ->condition('vid', $vid)
      ->condition('name', $termName)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($result)) {
      $term = $storage->create(['vid' => $vid, 'name' => $termName]);
      $term->save();
      return $term;
    }

    return $storage->load(reset($result));
  }

}

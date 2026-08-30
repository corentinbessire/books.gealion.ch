<?php

declare(strict_types=1);

namespace Drupal\books_catalog\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Field-level rules for the book bundle.
 */
class BookFieldHooks {

  /**
   * Implements hook_entity_bundle_field_info_alter().
   *
   * A book is identified by its ISBN, so two books may not share one.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The bundle's field definitions, by field name.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type being altered.
   * @param string $bundle
   *   The bundle being altered.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'node' || $bundle !== 'book') {
      return;
    }

    if (isset($fields['field_isbn'])) {
      $fields['field_isbn']->addConstraint('UniqueField');
    }
  }

}

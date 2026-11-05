<?php

/**
 * @file
 * Post update functions for Books - Catalog.
 */

declare(strict_types=1);

/**
 * Forget the schema version of the retired books_book_managment module.
 */
function books_catalog_post_update_forget_books_book_managment(): string {
  // The module's code was removed in f615781, but its entry in system.schema
  // stayed behind, and Drush warns about it on every run.
  \Drupal::service('update.update_hook_registry')
    ->deleteInstalledVersion('books_book_managment');
  return 'Removed the leftover schema version of books_book_managment.';
}

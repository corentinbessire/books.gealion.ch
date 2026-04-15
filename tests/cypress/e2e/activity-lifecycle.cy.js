/**
 * @file
 * E2E tests for the activity lifecycle (start, finish, abandon).
 */

describe('Activity Lifecycle', () => {
  beforeEach(() => {
    cy.drupalLogin('admin');
  });

  it('starts a reading activity for a book', () => {
    cy.fixture('book').then((book) => {
      // Ensure a book exists first.
      cy.drupalCreateNode('book', book.bookTitle);

      cy.visit(`/activity/start/${book.validIsbn}`);

      // Should redirect to activities view or show success message.
      cy.url().should('not.include', '/activity/start');
    });
  });

  it('finishes a reading activity', () => {
    // Create a book and activity via Drush, then finish it.
    // Use single quotes for the shell so PHP $variables are not expanded.
    cy.exec(
      "drush eval '" +
        '$book = \\Drupal::entityTypeManager()->getStorage("node")->create(["type" => "book", "title" => "Finish Test", "field_isbn" => "9780000000001"]); ' +
        '$book->save(); ' +
        '$activity = \\Drupal::entityTypeManager()->getStorage("node")->create(["type" => "activity", "title" => "Finish Test Activity", "field_book" => ["target_id" => $book->id()], "field_start_date" => date("Y-m-d")]); ' +
        '$activity->save(); ' +
        'echo $activity->id();' +
        "'",
    ).then((result) => {
      const activityId = result.stdout.trim();
      cy.visit(`/activity/${activityId}/finish`);
      cy.url().should('not.include', '/finish');
    });
  });

  it('abandons a reading activity', () => {
    cy.exec(
      "drush eval '" +
        '$book = \\Drupal::entityTypeManager()->getStorage("node")->create(["type" => "book", "title" => "Abandon Test", "field_isbn" => "9780000000002"]); ' +
        '$book->save(); ' +
        '$activity = \\Drupal::entityTypeManager()->getStorage("node")->create(["type" => "activity", "title" => "Abandon Test Activity", "field_book" => ["target_id" => $book->id()], "field_start_date" => date("Y-m-d")]); ' +
        '$activity->save(); ' +
        'echo $activity->id();' +
        "'",
    ).then((result) => {
      const activityId = result.stdout.trim();
      cy.visit(`/activity/${activityId}/abandon`);
      cy.url().should('not.include', '/abandon');
    });
  });
});

/**
 * @file
 * Custom Cypress commands for Drupal testing.
 */

/**
 * Log in to Drupal using a one-time login link via Drush.
 *
 * Usage: cy.drupalLogin('admin')
 *
 * Passing 'admin' logs in as uid 1 (the superadmin), regardless of the
 * actual username configured in Drupal. Any other value is treated as a
 * Drupal username.
 *
 * @param {string} username - 'admin' for uid 1, or an exact Drupal username.
 */
Cypress.Commands.add('drupalLogin', (username) => {
  const flag = username === 'admin' ? '--uid=1' : `--name=${username}`;
  cy.exec(`drush uli ${flag} --uri=https://books.ddev.site`).then((result) => {
    const loginUrl = result.stdout.trim();
    cy.visit(loginUrl);
  });
});

/**
 * Log out of Drupal.
 */
Cypress.Commands.add('drupalLogout', () => {
  cy.visit('/user/logout');
});

/**
 * Create a node via Drush.
 *
 * @param {string} type - The content type machine name.
 * @param {string} title - The node title.
 */
Cypress.Commands.add('drupalCreateNode', (type, title) => {
  cy.exec(
    `drush eval "\\Drupal::entityTypeManager()->getStorage('node')->create(['type' => '${type}', 'title' => '${title}'])->save();"`,
  );
});

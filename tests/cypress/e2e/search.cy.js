/**
 * @file
 * E2E tests for book search functionality.
 */

describe('Search', () => {
  beforeEach(() => {
    cy.drupalLogin('admin');
  });

  it('search page loads', () => {
    cy.visit('/books');
    cy.get('body').should('be.visible');
  });

  it('search returns results for existing content', () => {
    // Create a book to search for.
    cy.drupalCreateNode('book', 'Cypress Search Test Book');

    cy.visit('/books?search=Cypress+Search+Test');

    // Verify results appear.
    cy.contains('Cypress Search Test Book').should('be.visible');
  });

  it('search shows no results message for nonsense query', () => {
    cy.visit('/books?search=zzzznonexistentzzzzz');

    cy.get('body').should('be.visible');
  });
});

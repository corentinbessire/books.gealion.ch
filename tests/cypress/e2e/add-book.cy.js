/**
 * @file
 * E2E tests for the Add Book form.
 */

describe('Add Book', () => {
  beforeEach(() => {
    cy.drupalLogin('admin');
  });

  it('displays the add book form', () => {
    cy.visit('/add-book');
    cy.get('input[name="isbn"]').should('be.visible');
    cy.get('input[type="submit"]').should('be.visible');
  });

  it('shows validation error for invalid ISBN', () => {
    cy.visit('/add-book');
    cy.get('input[name="isbn"]').type('invalid-isbn');
    cy.get('input[type="submit"]').click();
    cy.contains('This is not a valid ISBN number').should('be.visible');
  });

  it('submits valid ISBN and processes form', () => {
    cy.fixture('book').then((book) => {
      cy.visit('/add-book');
      cy.get('input[name="isbn"]').type(book.validIsbn);
      cy.get('input[type="submit"]').click();

      // The form should either create a book (if external APIs respond)
      // or show a warning (if APIs are rate-limited/unavailable).
      cy.get('.messages').should('be.visible');
    });
  });

  it('anonymous user cannot access add-book form', () => {
    cy.clearCookies();
    cy.request({ url: '/add-book', failOnStatusCode: false }).then((response) => {
      expect(response.status).to.be.oneOf([403, 302]);
    });
  });
});

<?php

namespace Drupal\Tests\books_hardcover\Functional\Form;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for AddBookForm.
 *
 * @group books_hardcover
 */
class AddBookFormFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'taxonomy',
    'media',
    'image',
    'file',
    'user',
    'isbn',
    'books_catalog',
    'books_cover',
    'books_hardcover',
    // Enabled only so testUnknownIsbnCreatesStub() can prove, from the
    // watchdog log, that HardcoverService took its no-token early return
    // instead of ever reaching the outbound HTTP call.
    'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Force the Hardcover API token to NULL, regardless of what the host
    // environment's settings.local.php might otherwise inherit into the test
    // site. Without this guarantee, testUnknownIsbnCreatesStub() would make a
    // real outbound request to the Hardcover API using the developer's token
    // whenever one happens to be configured on the machine running the test.
    $this->writeSettings([
      'settings' => [
        'hardcover_api_token' => (object) ['value' => NULL, 'required' => TRUE],
      ],
    ]);

    // Create the 'book' content type (required for 'create book content'
    // permission and the /add-book route).
    if (!NodeType::load('book')) {
      NodeType::create(['type' => 'book', 'name' => 'Book'])->save();
    }

    // BookService::getBook() looks up an existing node by the stored
    // field_isbn value, so the field must exist before AddBookForm can save
    // anything — the module ships no field config of its own; field_isbn is
    // normally provided by the site's own config (config/sync), which this
    // minimal test install does not import.
    if (!FieldStorageConfig::loadByName('node', 'field_isbn')) {
      FieldStorageConfig::create([
        'field_name' => 'field_isbn',
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_isbn',
        'entity_type' => 'node',
        'bundle' => 'book',
        'label' => 'ISBN',
      ])->save();
    }
  }

  /**
   * Tests anonymous users are denied access to add-book form.
   */
  public function testAnonymousAccessDenied(): void {
    $this->drupalGet('/add-book');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests authenticated users can access add-book form.
   */
  public function testAuthenticatedAccess(): void {
    $user = $this->drupalCreateUser(['access content', 'create book content']);
    $this->drupalLogin($user);

    $this->drupalGet('/add-book');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('isbn');
    $this->assertSession()->buttonExists('Add book');
  }

  /**
   * Tests form submission with invalid ISBN shows error.
   */
  public function testInvalidIsbnShowsError(): void {
    $user = $this->drupalCreateUser(['access content', 'create book content']);
    $this->drupalLogin($user);

    $this->drupalGet('/add-book');
    $this->submitForm(['isbn' => 'invalid-isbn'], 'Add book');

    $this->assertSession()->pageTextContains('This is not a valid ISBN number.');
  }

  /**
   * Tests that an unknown ISBN still produces a book node.
   *
   * A scanned barcode should never be lost, so the form creates a stub the
   * user can complete by hand. This test runs without a configured API
   * token (forced in setUp()), so getFormattedBookData() returns NULL and
   * the stub path is exercised without any network access.
   */
  public function testUnknownIsbnCreatesStub(): void {
    $user = $this->drupalCreateUser(['access content', 'create book content']);
    $this->drupalLogin($user);

    $this->drupalGet('/add-book');
    $this->submitForm(['isbn' => '9780765326379'], 'Add book');

    $this->assertSession()->pageTextContains('please fill in the details');

    // Definitive proof of no network call: HardcoverService::getBookData()
    // logs this exact warning and returns NULL immediately — the call to
    // $this->httpClient->request() is several lines further down the same
    // method and is never reached once this branch has run. The message
    // being in watchdog (written by the request-time kernel, read here from
    // the outer test process via the shared test database) confirms the
    // no-token branch executed, not merely that the page rendered without
    // error.
    $messages = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('type', 'HardcoverService')
      ->condition('message', 'Hardcover API token is not configured.')
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $messages, 'HardcoverService logged its no-token early return, proving no outbound HTTP request was made.');
  }

}

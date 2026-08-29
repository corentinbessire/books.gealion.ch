# Hardcover Data Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Google Books and Open Library with Hardcover as the sole source of book metadata and covers, add moods to the site, and make syncing rate-limit-safe via a queue.

**Architecture:** A single `HardcoverService` performs one GraphQL request per book and maps the response to Drupal field values. It stays queue-agnostic and throws `HardcoverRateLimitException` when throttled. A `QueueWorker` translates that into core's `DelayedRequeueException` so cron reschedules the item instead of losing it. `AddBookForm` remains synchronous for immediate feedback; bulk syncing goes through the queue.

**Tech Stack:** Drupal 11.3, PHP 8.3, Guzzle, Drupal Queue API, Drush 13, PHPUnit, DDEV.

**Spec:** `docs/superpowers/specs/2026-08-29-hardcover-data-source-design.md`

## Global Constraints

- All commands run through DDEV: `ddev drush`, `ddev phpcs`, `ddev phpstan`, `ddev phpunit`. Never `vendor/bin/*`.
- Module path is `web/modules/custom/books_book_managment` — abbreviated **MODULE** below.
- Drupal coding standards: every class, method, and property needs a docblock. `ddev phpcs MODULE` must stay clean after every task.
- API endpoint: `https://api.hardcover.app/v1/graphql`, auth header `Authorization: Bearer <token>`.
- Token is read from `$settings['hardcover_api_token']`, already set in the gitignored `web/sites/default/settings.local.php`. **Never commit the token, never paste it into a test, fixture, spec, or log message.**
- `User-Agent` header on every API request, per Hardcover's docs.
- Rate limits: 5,000/day, 60/min, burst 10. Parse the IETF-draft `RateLimit` header, not the deprecated `X-RateLimit-*` headers.
- No rank cap on tags. Genres are filtered on consensus: reject purely numeric names, reject the genre stoplist, then keep `count >= max(2, 10% of the book's top genre count)`; when nothing clears the bar, import no genres. Moods get only the numeric-name rejection. Hardcover itself caps `cached_tags` at 10 per category.
- Genre stoplist, compared case-insensitively: `General`, `Fiction`, `Literature`, `Literary Collections`, `literary criticism`, `English fiction`, `Juvenile Fiction`, `Juvenile Nonfiction`, `Short stories`, `Movie`.
- Tag names are normalised to upper-case first character so `emotional` and `Emotional` do not create duplicate taxonomy terms.
- Test fixture: `MODULE/tests/fixtures/hardcover-oathbringer.json`, a real captured API response. Tests read it; they never hit the network.
- Commit after every task. Branch is `feat/hardcover-data-source`.

## Captured API response shape

Confirmed against the live API on 2026-08-29 (ISBN 9780765326379):

```
data.editions[0] = {
  title, pages, release_date, isbn_13,
  publisher: {name},
  image: {url},
  contributions: [{author: {name}}],
  book: {
    title, description,
    cached_tags: {
      "Tag": [...], "Mood": [...], "Genre": [...], "Content Warning": [...]
    },
    default_cover_edition: {image: {url}},
    book_series: [{position, featured, series: {name}}]
  }
}
```

Each `cached_tags` entry is `{tag, count, tagSlug, category, categorySlug, spoilerRatio}`.
Response headers include `ratelimit: "Free";r=9;t=0, "daily";r=4999;t=38580`.

## File Structure

**Create**
- `MODULE/src/Exception/HardcoverRateLimitException.php` — carries a retry delay; no other behaviour.
- `MODULE/src/Services/HardcoverService.php` — transport + mapping. Mapping methods are pure.
- `MODULE/src/Plugin/QueueWorker/HardcoverBookSync.php` — one queue item = one book.
- `MODULE/books_book_managment.install` — the field migration update hook.
- `MODULE/tests/src/Unit/Services/HardcoverServiceTest.php`
- `MODULE/tests/src/Kernel/Plugin/QueueWorker/HardcoverBookSyncKernelTest.php`
- `config/sync/facets.facet.moods.yml`
- `config/sync/field.storage.node.field_moods.yml`, `field.field.node.book.field_moods.yml`
- `config/sync/field.storage.node.field_serie_position.yml`, `field.field.node.book.field_serie_position.yml`
- `config/sync/taxonomy.vocabulary.mood.yml`

**Modify**
- `MODULE/src/Services/CoverDownloadService.php` — takes a URL instead of guessing one.
- `MODULE/src/Services/BooksUtilsService.php` — new field mappings, gap-fill mode, `getAllBooks()`.
- `MODULE/src/Form/AddBookForm.php`, `UpdateBookForm.php`
- `MODULE/src/Commands/BooksBookManagmentCommands.php`
- `MODULE/books_book_managment.services.yml`
- `web/themes/custom/books/templates/content/node/book/node--book.html.twig`
- `config/sync/` — displays, views, search index

**Delete**
- `MODULE/src/Services/BookDataServiceInterface.php`, `GoogleBooksService.php`, `OpenLibraryService.php`
- `MODULE/src/Batches/MissingCoverBatch.php`
- The three corresponding test files

---

### Task 1: Rate limit exception and Hardcover transport

**Files:**
- Create: `MODULE/src/Exception/HardcoverRateLimitException.php`
- Create: `MODULE/src/Services/HardcoverService.php`
- Create: `MODULE/tests/src/Unit/Services/HardcoverServiceTest.php`
- Modify: `MODULE/books_book_managment.services.yml`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `HardcoverRateLimitException::getRetryAfter(): int`
  - `HardcoverService::getBookData(string|int $isbn): ?array` — returns the single edition array or `NULL`
  - `HardcoverService::parseRateLimitHeader(string $header): array` — static, returns `[['name' => string, 'remaining' => int, 'reset' => int], ...]`
  - Service id `books.hardcover`

- [ ] **Step 1: Write the failing test**

Create `MODULE/tests/src/Unit/Services/HardcoverServiceTest.php`:

```php
<?php

namespace Drupal\Tests\books_book_managment\Unit\Services;

use Drupal\books_book_managment\Exception\HardcoverRateLimitException;
use Drupal\books_book_managment\Services\HardcoverService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\isbn\IsbnToolsServiceInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for HardcoverService.
 *
 * @group books_book_managment
 * @coversDefaultClass \Drupal\books_book_managment\Services\HardcoverService
 */
class HardcoverServiceTest extends UnitTestCase {

  /**
   * The mocked HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The mocked ISBN tools service.
   *
   * @var \Drupal\isbn\IsbnToolsServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $isbnTools;

  /**
   * The mocked time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')
      ->willReturn($this->createMock(LoggerChannelInterface::class));
    $this->isbnTools = $this->createMock(IsbnToolsServiceInterface::class);
    $this->isbnTools->method('convertIsbn13to10')->willReturn('0765326379');
    $this->time = $this->createMock(TimeInterface::class);
    // getCurrentTime, not getRequestTime: the latter is frozen for the life of
    // the PHP process, so a throttle set during a long-running queue drain
    // could never self-clear.
    $this->time->method('getCurrentTime')->willReturn(1000);
  }

  /**
   * Builds the service with a given settings array.
   *
   * @param array $settings
   *   Settings to seed the Settings singleton with.
   *
   * @return \Drupal\books_book_managment\Services\HardcoverService
   *   The service under test.
   */
  protected function buildService(array $settings = ['hardcover_api_token' => 'test-token']): HardcoverService {
    return new HardcoverService(
      $this->httpClient,
      $this->loggerFactory,
      new Settings($settings),
      $this->isbnTools,
      $this->time,
    );
  }

  /**
   * Returns the captured live API response.
   *
   * @return string
   *   Raw JSON body.
   */
  protected function fixture(): string {
    return file_get_contents(__DIR__ . '/../../../fixtures/hardcover-oathbringer.json');
  }

  /**
   * Tests that a successful response returns the edition array.
   *
   * @covers ::getBookData
   */
  public function testGetBookDataReturnsEdition(): void {
    $this->httpClient->method('request')
      ->willReturn(new Response(200, ['RateLimit' => '"Free";r=9;t=0'], $this->fixture()));

    $edition = $this->buildService()->getBookData('9780765326379');

    $this->assertIsArray($edition);
    $this->assertSame('Oathbringer', $edition['title']);
    $this->assertSame(1243, $edition['pages']);
  }

  /**
   * Tests that an empty editions array returns NULL.
   *
   * @covers ::getBookData
   */
  public function testGetBookDataReturnsNullWhenNotFound(): void {
    $this->httpClient->method('request')
      ->willReturn(new Response(200, [], '{"data":{"editions":[]}}'));

    $this->assertNull($this->buildService()->getBookData('9780000000000'));
  }

  /**
   * Tests that a missing token short-circuits without a request.
   *
   * @covers ::getBookData
   */
  public function testGetBookDataReturnsNullWithoutToken(): void {
    $this->httpClient->expects($this->never())->method('request');

    $this->assertNull($this->buildService([])->getBookData('9780765326379'));
  }

  /**
   * Tests that a 429 response raises a rate limit exception.
   *
   * @covers ::getBookData
   */
  public function testRateLimitExceptionOn429(): void {
    $this->httpClient->method('request')
      ->willReturn(new Response(429, ['Retry-After' => '42'], '{}'));

    $this->expectException(HardcoverRateLimitException::class);
    try {
      $this->buildService()->getBookData('9780765326379');
    }
    catch (HardcoverRateLimitException $e) {
      $this->assertSame(42, $e->getRetryAfter());
      throw $e;
    }
  }

  /**
   * Tests that an exhausted bucket throttles the following request.
   *
   * The response that reports r=0 is still usable, so it is returned; only the
   * next call throws.
   *
   * @covers ::getBookData
   */
  public function testExhaustedBucketThrottlesNextRequest(): void {
    $this->httpClient->method('request')
      ->willReturn(new Response(200, ['RateLimit' => '"Free";r=0;t=30'], $this->fixture()));

    $service = $this->buildService();
    $this->assertIsArray($service->getBookData('9780765326379'));

    $this->expectException(HardcoverRateLimitException::class);
    $service->getBookData('9780765326379');
  }

  /**
   * Tests parsing of the IETF RateLimit header.
   *
   * @covers ::parseRateLimitHeader
   */
  public function testParseRateLimitHeader(): void {
    $buckets = HardcoverService::parseRateLimitHeader('"Free";r=9;t=0, "daily";r=4999;t=38580');

    $this->assertCount(2, $buckets);
    $this->assertSame(['name' => 'Free', 'remaining' => 9, 'reset' => 0], $buckets[0]);
    $this->assertSame(['name' => 'daily', 'remaining' => 4999, 'reset' => 38580], $buckets[1]);
  }

  /**
   * Tests that a malformed header is ignored rather than fataling.
   *
   * @covers ::parseRateLimitHeader
   */
  public function testParseRateLimitHeaderIgnoresGarbage(): void {
    $this->assertSame([], HardcoverService::parseRateLimitHeader('not a header'));
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/HardcoverServiceTest.php`
Expected: FAIL — `Class "Drupal\books_book_managment\Services\HardcoverService" not found`.

- [ ] **Step 3: Write the exception**

Create `MODULE/src/Exception/HardcoverRateLimitException.php`:

```php
<?php

namespace Drupal\books_book_managment\Exception;

/**
 * Thrown when the Hardcover API rate limit has been reached.
 *
 * Carries the number of seconds the caller should wait. The service raising it
 * knows nothing about queues or batches; callers decide how to honour the wait.
 */
class HardcoverRateLimitException extends \RuntimeException {

  /**
   * Constructs a HardcoverRateLimitException.
   *
   * @param int $retryAfter
   *   Seconds to wait before retrying. Always at least 1.
   * @param string $message
   *   The exception message.
   */
  public function __construct(
    protected readonly int $retryAfter,
    string $message = 'Hardcover API rate limit reached.',
  ) {
    parent::__construct($message);
  }

  /**
   * Gets the number of seconds to wait before retrying.
   *
   * @return int
   *   Seconds to wait.
   */
  public function getRetryAfter(): int {
    return $this->retryAfter;
  }

}
```

- [ ] **Step 4: Write the transport half of the service**

Create `MODULE/src/Services/HardcoverService.php`. Only the transport methods for now — `formatBookData()` arrives in Task 2.

```php
<?php

namespace Drupal\books_book_managment\Services;

use Drupal\books_book_managment\Exception\HardcoverRateLimitException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\isbn\IsbnToolsServiceInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Reads book data from the Hardcover GraphQL API.
 *
 * One request returns the edition, its parent work, series membership, crowd
 * tags and a cover URL. The service is deliberately unaware of queues: when it
 * is throttled it throws HardcoverRateLimitException and lets the caller decide.
 */
class HardcoverService {

  /**
   * The Hardcover GraphQL endpoint.
   */
  public const API_ENDPOINT = 'https://api.hardcover.app/v1/graphql';

  /**
   * User agent identifying this site, as the Hardcover docs request.
   */
  public const USER_AGENT = 'books.gealion.ch Drupal importer';

  /**
   * Matches one bucket in an IETF draft RateLimit header.
   */
  protected const RATE_LIMIT_PATTERN = '/"(?<name>[^"]+)";\s*r=(?<remaining>\d+);\s*t=(?<reset>\d+)/';

  /**
   * The GraphQL query fetching everything the site maps.
   */
  protected const BOOK_QUERY = <<<'GRAPHQL'
    query BookByIsbn($isbn13: String!, $isbn10: String!) {
      editions(
        where: {_or: [{isbn_13: {_eq: $isbn13}}, {isbn_10: {_eq: $isbn10}}]}
        order_by: {users_count: desc}
        limit: 1
      ) {
        title
        pages
        release_date
        isbn_13
        publisher { name }
        image { url }
        contributions { author { name } }
        book {
          title
          description
          cached_tags
          default_cover_edition { image { url } }
          book_series { position featured series { name } }
        }
      }
    }
    GRAPHQL;

  /**
   * Unix timestamp before which requests must not be attempted.
   *
   * @var int
   */
  protected int $throttledUntil = 0;

  /**
   * Constructs a HardcoverService object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Guzzle client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Drupal logger channel factory service.
   * @param \Drupal\Core\Site\Settings $settings
   *   Drupal settings service, source of the API token.
   * @param \Drupal\isbn\IsbnToolsServiceInterface $isbnTools
   *   ISBN tools service, used to derive the ISBN-10.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service. Elapsed-time decisions use getCurrentTime(), never
   *   getRequestTime(), which is frozen for the life of the process.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
    protected Settings $settings,
    protected IsbnToolsServiceInterface $isbnTools,
    protected TimeInterface $time,
  ) {}

  /**
   * Gets the raw Hardcover edition record for an ISBN.
   *
   * @param string|int $isbn
   *   ISBN of the book to look up.
   *
   * @return array|null
   *   The edition array, or NULL when not found or not configured.
   *
   * @throws \Drupal\books_book_managment\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function getBookData(string|int $isbn): ?array {
    $logger = $this->loggerChannelFactory->get('HardcoverService');

    $token = $this->settings->get('hardcover_api_token');
    if (empty($token)) {
      $logger->warning('Hardcover API token is not configured.');
      return NULL;
    }

    $now = $this->time->getCurrentTime();
    if ($this->throttledUntil > $now) {
      throw new HardcoverRateLimitException($this->throttledUntil - $now);
    }

    $isbn13 = (string) $isbn;
    $isbn10 = $this->isbnTools->convertIsbn13to10($isbn13) ?? '';

    try {
      $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'User-Agent' => self::USER_AGENT,
        ],
        'json' => [
          'query' => self::BOOK_QUERY,
          'variables' => ['isbn13' => $isbn13, 'isbn10' => $isbn10],
        ],
        'http_errors' => FALSE,
      ]);
    }
    catch (RequestException $e) {
      $logger->alert($e->getCode() . ' : ' . $e->getMessage());
      return NULL;
    }

    if ($response->getStatusCode() === 429) {
      // Parse before applying the floor: `(int) $h ?: 60` would turn an
      // explicit `Retry-After: 0` into a full minute's wait.
      $header = $response->getHeaderLine('Retry-After');
      $retryAfter = is_numeric($header) ? max(1, (int) $header) : 60;
      $this->throttledUntil = $now + $retryAfter;
      throw new HardcoverRateLimitException($retryAfter);
    }

    if ($response->getStatusCode() !== 200) {
      $logger->alert('Hardcover API returned HTTP @code for ISBN @isbn.', [
        '@code' => $response->getStatusCode(),
        '@isbn' => $isbn13,
      ]);
      return NULL;
    }

    $this->recordRateLimitState($response, $now);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!empty($data['errors'])) {
      $logger->alert('Hardcover API error for ISBN @isbn : @error', [
        '@isbn' => $isbn13,
        '@error' => json_encode($data['errors']),
      ]);
      return NULL;
    }

    $editions = $data['data']['editions'] ?? [];
    if (empty($editions)) {
      $logger->notice('No Hardcover data for ISBN @isbn.', ['@isbn' => $isbn13]);
      return NULL;
    }

    return reset($editions);
  }

  /**
   * Records the throttle deadline when a rate limit bucket is exhausted.
   *
   * The response carrying r=0 is still valid and is used; only the following
   * request is held back, so no successful response is ever wasted.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The API response.
   * @param int $now
   *   Current request time.
   */
  protected function recordRateLimitState(ResponseInterface $response, int $now): void {
    foreach (self::parseRateLimitHeader($response->getHeaderLine('RateLimit')) as $bucket) {
      if ($bucket['remaining'] === 0) {
        $this->throttledUntil = max($this->throttledUntil, $now + max(1, $bucket['reset']));
      }
    }
  }

  /**
   * Parses an IETF draft RateLimit header into its buckets.
   *
   * Example input: '"Free";r=9;t=0, "daily";r=4999;t=38580'.
   *
   * @param string $header
   *   The raw header value.
   *
   * @return array
   *   List of ['name' => string, 'remaining' => int, 'reset' => int].
   */
  public static function parseRateLimitHeader(string $header): array {
    if (!preg_match_all(self::RATE_LIMIT_PATTERN, $header, $matches, PREG_SET_ORDER)) {
      return [];
    }
    $buckets = [];
    foreach ($matches as $match) {
      $buckets[] = [
        'name' => $match['name'],
        'remaining' => (int) $match['remaining'],
        'reset' => (int) $match['reset'],
      ];
    }
    return $buckets;
  }

}
```

- [ ] **Step 5: Register the service**

In `MODULE/books_book_managment.services.yml`, add above `books.books_utils`:

```yaml
  books.hardcover:
    class: Drupal\books_book_managment\Services\HardcoverService
    arguments:
      - '@http_client'
      - '@logger.factory'
      - '@settings'
      - '@isbn.isbn_service'
      - '@datetime.time'
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/HardcoverServiceTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Lint**

Run: `ddev phpcs web/modules/custom/books_book_managment`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add web/modules/custom/books_book_managment
git commit -m "feat(hardcover): Add Hardcover API transport with rate limit handling"
```

---

### Task 2: Response mapping

**Files:**
- Modify: `MODULE/src/Services/HardcoverService.php`
- Modify: `MODULE/tests/src/Unit/Services/HardcoverServiceTest.php`

**Interfaces:**
- Consumes: `HardcoverService::getBookData()` from Task 1.
- Produces:
  - `HardcoverService::formatBookData(array $edition): array` — keys `title`, `field_isbn`, `field_pages`, `field_release`, `field_publisher`, `field_excerpt`, `field_authors` (list of strings), `field_serie` (string|null), `field_serie_position` (float|null), `field_genres` (list of strings), `field_moods` (list of strings), `cover_url` (string|null)
  - `HardcoverService::getFormattedBookData(string|int $isbn): ?array`
  - `HardcoverService::normaliseTagName(string $name): string` — static

- [ ] **Step 1: Write the failing tests**

Append these methods to `HardcoverServiceTest`:

```php
  /**
   * Decodes the fixture down to the single edition array.
   *
   * @return array
   *   The edition array.
   */
  protected function fixtureEdition(): array {
    $data = json_decode($this->fixture(), TRUE);
    return $data['data']['editions'][0];
  }

  /**
   * Tests scalar field mapping.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataMapsScalars(): void {
    $formatted = $this->buildService()->formatBookData($this->fixtureEdition());

    $this->assertSame('Oathbringer', $formatted['title']);
    $this->assertSame('9780765326379', $formatted['field_isbn']);
    $this->assertSame(1243, $formatted['field_pages']);
    $this->assertSame('2017-11-14', $formatted['field_release']);
    $this->assertSame('Tor Books', $formatted['field_publisher']);
    $this->assertSame(['Brandon Sanderson'], $formatted['field_authors']);
    $this->assertStringContainsString('Stormlight Archive', $formatted['field_excerpt']);
  }

  /**
   * Tests that the featured series wins and its position is kept as a float.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataPrefersFeaturedSeries(): void {
    $formatted = $this->buildService()->formatBookData($this->fixtureEdition());

    $this->assertSame('The Stormlight Archive', $formatted['field_serie']);
    $this->assertSame(3.0, $formatted['field_serie_position']);
  }

  /**
   * Tests that a fractional series position survives mapping.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataKeepsFractionalPosition(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['book_series'] = [
      ['position' => 1.5, 'featured' => TRUE, 'series' => ['name' => 'Novellas']],
    ];

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame(1.5, $formatted['field_serie_position']);
  }

  /**
   * Tests that genres are consensus-filtered and moods are not.
   *
   * The fixture's top genre has count 13, so the bar is max(2, 1.3) = 2. That
   * admits Fantasy (13), Science Fiction & Fantasy (3) and Fiction (2); Fiction
   * is then removed by the stoplist. Moods keep all ten.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataFiltersGenresButNotMoods(): void {
    $formatted = $this->buildService()->formatBookData($this->fixtureEdition());

    $this->assertSame(['Fantasy', 'Science Fiction & Fantasy'], $formatted['field_genres']);
    $this->assertCount(10, $formatted['field_moods']);
    $this->assertSame('Adventurous', $formatted['field_moods'][0]);
  }

  /**
   * Tests that low-consensus junk tags are dropped.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataDropsLowConsensusTags(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['cached_tags']['Genre'] = [
      ['tag' => 'Classics', 'count' => 22],
      ['tag' => 'Romance', 'count' => 15],
      ['tag' => 'Historical Fiction', 'count' => 3],
      ['tag' => 'Russian language', 'count' => 1],
      ['tag' => 'Comics', 'count' => 1],
    ];

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame(['Classics', 'Romance', 'Historical Fiction'], $formatted['field_genres']);
  }

  /**
   * Tests that the stoplist removes non-genre labels.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataAppliesGenreStoplist(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['cached_tags']['Genre'] = [
      ['tag' => 'General', 'count' => 40],
      ['tag' => 'Short stories', 'count' => 30],
      ['tag' => 'Philosophy', 'count' => 20],
    ];

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame(['Philosophy'], $formatted['field_genres']);
  }

  /**
   * Tests that a book with no tag consensus gets no genres at all.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataDropsAllGenresWithoutConsensus(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['cached_tags']['Genre'] = [
      ['tag' => 'Classics', 'count' => 1],
      ['tag' => 'Romance', 'count' => 1],
      ['tag' => 'History', 'count' => 1],
    ];

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame([], $formatted['field_genres']);
  }

  /**
   * Tests that purely numeric tag names are rejected in both categories.
   *
   * Hardcover's live data contains leaked timestamps as mood tags.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataRejectsNumericTags(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['cached_tags']['Mood'] = [
      ['tag' => '1735865543602', 'count' => 99],
      ['tag' => 'tense', 'count' => 53],
    ];

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame(['Tense'], $formatted['field_moods']);
  }

  /**
   * Tests that lower-case tags are normalised so terms do not duplicate.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataNormalisesTagCase(): void {
    $formatted = $this->buildService()->formatBookData($this->fixtureEdition());

    $this->assertContains('Emotional', $formatted['field_moods']);
    $this->assertNotContains('emotional', $formatted['field_moods']);
  }

  /**
   * Tests that malformed cached_tags degrade to empty lists.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataToleratesBrokenTags(): void {
    $edition = $this->fixtureEdition();
    $edition['book']['cached_tags'] = 'not-an-array';

    $formatted = $this->buildService()->formatBookData($edition);

    $this->assertSame([], $formatted['field_genres']);
    $this->assertSame([], $formatted['field_moods']);
  }

  /**
   * Tests the cover URL and its fallback to the default cover edition.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataCoverUrlFallsBack(): void {
    $service = $this->buildService();
    $edition = $this->fixtureEdition();

    $this->assertStringContainsString('assets.hardcover.app', $service->formatBookData($edition)['cover_url']);

    $edition['image'] = NULL;
    $edition['book']['default_cover_edition']['image']['url'] = 'https://example.com/fallback.jpg';
    $this->assertSame('https://example.com/fallback.jpg', $service->formatBookData($edition)['cover_url']);

    $edition['book']['default_cover_edition'] = NULL;
    $this->assertNull($service->formatBookData($edition)['cover_url']);
  }

  /**
   * Tests that a missing release date maps to NULL rather than a bogus date.
   *
   * @covers ::formatBookData
   */
  public function testFormatBookDataHandlesMissingDate(): void {
    $edition = $this->fixtureEdition();
    $edition['release_date'] = NULL;

    $this->assertNull($this->buildService()->formatBookData($edition)['field_release']);
  }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/HardcoverServiceTest.php`
Expected: FAIL — `Call to undefined method ...::formatBookData()`.

- [ ] **Step 3: Implement the mapping methods**

First add the tag-filtering constants to `HardcoverService`, directly after the existing `RATE_LIMIT_PATTERN` constant:

```php
  /**
   * Minimum agreement a genre needs, as a share of the book's top genre count.
   */
  protected const TAG_CONSENSUS_RATIO = 0.10;

  /**
   * Absolute floor for genre agreement, whatever the top count is.
   */
  protected const TAG_MIN_COUNT = 2;

  /**
   * Lower-case labels that Hardcover files under Genre but which are not one.
   */
  protected const GENRE_STOPLIST = [
    'general',
    'fiction',
    'literature',
    'literary collections',
    'literary criticism',
    'english fiction',
    'juvenile fiction',
    'juvenile nonfiction',
    'short stories',
    'movie',
  ];
```

Then append the following methods, before the closing brace:

```php
  /**
   * Maps a Hardcover edition record onto Drupal field values.
   *
   * Pure: no I/O, so it is unit-testable against a captured response.
   *
   * @param array $edition
   *   The raw edition array from the API.
   *
   * @return array
   *   Formatted data keyed by field name, plus a 'cover_url' key.
   */
  public function formatBookData(array $edition): array {
    $book = is_array($edition['book'] ?? NULL) ? $edition['book'] : [];
    $series = $this->extractSeries($book['book_series'] ?? []);

    return [
      'title' => ($edition['title'] ?? '') ?: ($book['title'] ?? ''),
      'field_isbn' => $edition['isbn_13'] ?? NULL,
      'field_pages' => $edition['pages'] ?? NULL,
      'field_release' => $this->formatReleaseDate($edition['release_date'] ?? NULL),
      'field_publisher' => $edition['publisher']['name'] ?? NULL,
      'field_excerpt' => $book['description'] ?? NULL,
      'field_authors' => $this->extractAuthors($edition['contributions'] ?? []),
      'field_serie' => $series['name'],
      'field_serie_position' => $series['position'],
      'field_genres' => $this->extractGenres($book['cached_tags'] ?? NULL),
      'field_moods' => $this->extractMoods($book['cached_tags'] ?? NULL),
      'cover_url' => $edition['image']['url']
        ?? $book['default_cover_edition']['image']['url']
        ?? NULL,
    ];
  }

  /**
   * Gets formatted book data for an ISBN in one call.
   *
   * @param string|int $isbn
   *   ISBN of the book.
   *
   * @return array|null
   *   Formatted data, or NULL when Hardcover has no match.
   *
   * @throws \Drupal\books_book_managment\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function getFormattedBookData(string|int $isbn): ?array {
    $edition = $this->getBookData($isbn);
    return $edition ? $this->formatBookData($edition) : NULL;
  }

  /**
   * Extracts author names from an edition's contributions.
   *
   * @param array $contributions
   *   The contributions array.
   *
   * @return string[]
   *   Author names, de-duplicated, order preserved.
   */
  protected function extractAuthors(array $contributions): array {
    $names = [];
    foreach ($contributions as $contribution) {
      $name = $contribution['author']['name'] ?? NULL;
      if (is_string($name) && trim($name) !== '') {
        $names[] = trim($name);
      }
    }
    return array_values(array_unique($names));
  }

  /**
   * Picks the series a book should be filed under.
   *
   * A book can belong to several series; Hardcover marks one as featured. The
   * featured entry wins, otherwise the first is used.
   *
   * @param array $bookSeries
   *   The book_series array.
   *
   * @return array
   *   ['name' => string|null, 'position' => float|null].
   */
  protected function extractSeries(array $bookSeries): array {
    $chosen = NULL;
    foreach ($bookSeries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (!empty($entry['featured'])) {
        $chosen = $entry;
        break;
      }
      $chosen ??= $entry;
    }

    if ($chosen === NULL) {
      return ['name' => NULL, 'position' => NULL];
    }

    $position = $chosen['position'] ?? NULL;
    return [
      'name' => $chosen['series']['name'] ?? NULL,
      'position' => is_numeric($position) ? (float) $position : NULL,
    ];
  }

  /**
   * Extracts the genres a book should be filed under.
   *
   * Hardcover's Genre tags are free text merged from publisher metadata and
   * carry real noise — sampled books returned 'Russian language', 'Comics' and
   * 'Movie'. There is no rank cap; tags are filtered on agreement instead, so a
   * book whose ten genres are all well-agreed keeps all ten. When nothing
   * clears the bar the book gets no genres, which is more honest than an
   * arbitrary pick and keeps junk terms out of the taxonomy.
   *
   * @param mixed $cachedTags
   *   The cached_tags value, which the API types loosely as jsonb.
   *
   * @return string[]
   *   Normalised genre names, most-agreed first.
   */
  protected function extractGenres(mixed $cachedTags): array {
    $tags = array_values(array_filter(
      $this->extractTags($cachedTags, 'Genre'),
      static fn(array $tag): bool => !in_array(mb_strtolower($tag['name']), self::GENRE_STOPLIST, TRUE),
    ));

    if ($tags === []) {
      return [];
    }

    $threshold = max(self::TAG_MIN_COUNT, $tags[0]['count'] * self::TAG_CONSENSUS_RATIO);

    return array_values(array_map(
      static fn(array $tag): string => $tag['name'],
      array_filter($tags, static fn(array $tag): bool => $tag['count'] >= $threshold),
    ));
  }

  /**
   * Extracts a book's moods.
   *
   * Unlike genres these come from a closed picker — sixteen distinct values
   * across the whole API sample — so they need no consensus filtering.
   *
   * @param mixed $cachedTags
   *   The cached_tags value.
   *
   * @return string[]
   *   Normalised mood names, most-agreed first.
   */
  protected function extractMoods(mixed $cachedTags): array {
    return array_map(
      static fn(array $tag): string => $tag['name'],
      $this->extractTags($cachedTags, 'Mood'),
    );
  }

  /**
   * Extracts and cleans one cached_tags category, ranked by agreement.
   *
   * @param mixed $cachedTags
   *   The cached_tags value, which the API types loosely as jsonb.
   * @param string $category
   *   Category key, for example 'Genre' or 'Mood'.
   *
   * @return array
   *   List of ['name' => string, 'count' => int], most-agreed first.
   */
  protected function extractTags(mixed $cachedTags, string $category): array {
    if (!is_array($cachedTags) || !is_array($cachedTags[$category] ?? NULL)) {
      return [];
    }

    $tags = [];
    $seen = [];
    foreach ($cachedTags[$category] as $tag) {
      if (!is_array($tag) || !is_string($tag['tag'] ?? NULL)) {
        continue;
      }

      $name = self::normaliseTagName($tag['tag']);
      // Reject empty and purely numeric names. Hardcover's live data contains
      // leaked timestamps such as '1735865543602' presented as mood tags.
      if ($name === '' || preg_match('/^\d+$/', $name) === 1) {
        continue;
      }

      $key = mb_strtolower($name);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;

      $tags[] = ['name' => $name, 'count' => (int) ($tag['count'] ?? 0)];
    }

    usort($tags, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    return $tags;
  }

  /**
   * Normalises a tag name so equivalent tags map to one taxonomy term.
   *
   * Hardcover's crowd tags are inconsistently cased: 'Adventurous' alongside
   * 'emotional'. Without this, each casing would create its own term.
   *
   * @param string $name
   *   The raw tag name.
   *
   * @return string
   *   The normalised name.
   */
  public static function normaliseTagName(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
  }

  /**
   * Formats an API release date as Y-m-d.
   *
   * @param string|null $releaseDate
   *   The raw release date.
   *
   * @return string|null
   *   Formatted date, or NULL when absent or unparseable.
   */
  protected function formatReleaseDate(?string $releaseDate): ?string {
    if (empty($releaseDate)) {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($releaseDate))->format('Y-m-d');
    }
    catch (\Exception) {
      return NULL;
    }
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/HardcoverServiceTest.php`
Expected: PASS, 15 tests.

- [ ] **Step 5: Lint and commit**

```bash
ddev phpcs web/modules/custom/books_book_managment
git add web/modules/custom/books_book_managment
git commit -m "feat(hardcover): Map API responses to book fields including series, genres and moods"
```

---

### Task 3: Mood vocabulary, mood field, and decimal series position field

This task creates config only. No PHP, no tests — verification is that `ddev drush cim` round-trips cleanly and the fields appear on the book form.

**Files:**
- Create (via export): `config/sync/taxonomy.vocabulary.mood.yml`, `field.storage.node.field_moods.yml`, `field.field.node.book.field_moods.yml`, `field.storage.node.field_serie_position.yml`, `field.field.node.book.field_serie_position.yml`
- Modify (via export): `core.entity_form_display.node.book.default.yml`, `core.entity_view_display.node.book.default.yml`, `core.entity_view_display.node.book.teaser.yml`

**Interfaces:**
- Consumes: nothing.
- Produces: `field_moods` (entity reference to `mood`, unlimited, auto-create) and `field_serie_position` (decimal, precision 6, scale 2) on `node.book`.

- [ ] **Step 1: Create the vocabulary and fields**

Run:

```bash
ddev drush php:eval '
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!Vocabulary::load("mood")) {
  Vocabulary::create(["vid" => "mood", "name" => "Mood"])->save();
}

if (!FieldStorageConfig::loadByName("node", "field_moods")) {
  FieldStorageConfig::create([
    "field_name" => "field_moods",
    "entity_type" => "node",
    "type" => "entity_reference",
    "cardinality" => -1,
    "settings" => ["target_type" => "taxonomy_term"],
  ])->save();
}
if (!FieldConfig::loadByName("node", "book", "field_moods")) {
  FieldConfig::create([
    "field_name" => "field_moods",
    "entity_type" => "node",
    "bundle" => "book",
    "label" => "Moods",
    "settings" => [
      "handler" => "default:taxonomy_term",
      "handler_settings" => [
        "target_bundles" => ["mood" => "mood"],
        "sort" => ["field" => "name", "direction" => "asc"],
        "auto_create" => TRUE,
        "auto_create_bundle" => "",
      ],
    ],
  ])->save();
}

if (!FieldStorageConfig::loadByName("node", "field_serie_position")) {
  FieldStorageConfig::create([
    "field_name" => "field_serie_position",
    "entity_type" => "node",
    "type" => "decimal",
    "cardinality" => 1,
    "settings" => ["precision" => 6, "scale" => 2],
  ])->save();
}
if (!FieldConfig::loadByName("node", "book", "field_serie_position")) {
  FieldConfig::create([
    "field_name" => "field_serie_position",
    "entity_type" => "node",
    "bundle" => "book",
    "label" => "Series position",
  ])->save();
}
print "created\n";
'
```

Expected output: `created`.

- [ ] **Step 2: Place both fields on the displays**

Run:

```bash
ddev drush php:eval '
$fd = \Drupal::service("entity_display.repository")->getFormDisplay("node", "book", "default");
$fd->setComponent("field_moods", ["type" => "entity_reference_autocomplete_tags", "weight" => 60])
   ->setComponent("field_serie_position", ["type" => "number", "weight" => 59])
   ->save();

$vd = \Drupal::service("entity_display.repository")->getViewDisplay("node", "book", "default");
$vd->setComponent("field_moods", ["type" => "entity_reference_label", "label" => "hidden", "settings" => ["link" => TRUE], "weight" => 107])
   ->setComponent("field_serie_position", ["type" => "number_decimal", "label" => "hidden", "settings" => ["thousand_separator" => "", "decimal_separator" => ".", "scale" => 2, "prefix_suffix" => TRUE], "weight" => 105])
   ->save();

$td = \Drupal::service("entity_display.repository")->getViewDisplay("node", "book", "teaser");
$td->removeComponent("field_moods")->removeComponent("field_serie_position")->save();
print "displays updated\n";
'
```

Expected output: `displays updated`.

- [ ] **Step 3: Export and inspect**

```bash
ddev drush cex -y
git status --porcelain config/sync
```

Expected: the five new files listed as untracked, plus modified display configs.

- [ ] **Step 4: Verify the config round-trips**

Run: `ddev drush cim -y`
Expected: `There are no changes to import.`

- [ ] **Step 5: Commit**

```bash
git add config/sync
git commit -m "feat(books): Add mood vocabulary, field_moods and decimal field_serie_position"
```

---

### Task 4: Migrate field_serie_number to field_serie_position

`field_serie_number` is an integer and cannot be converted in place. This task copies its data across, repoints every consumer, and removes the old field.

**Files:**
- Create: `MODULE/books_book_managment.install`
- Modify: `web/themes/custom/books/templates/content/node/book/node--book.html.twig:14`
- Modify: `config/sync/views.view.taxonomy_term.yml`, `config/sync/views.view.books_admin.yml`

**Interfaces:**
- Consumes: `field_serie_position` from Task 3.
- Produces: `field_serie_number` no longer exists anywhere.

- [ ] **Step 1: Write the update hook**

Create `MODULE/books_book_managment.install`:

```php
<?php

/**
 * @file
 * Install, update and uninstall functions for Books - Book Managment.
 */

use Drupal\field\Entity\FieldStorageConfig;

/**
 * Copy field_serie_number into field_serie_position and drop the old field.
 *
 * Hardcover reports series positions as floats (novellas sit at 1.5), which the
 * old integer field could not hold.
 */
function books_book_managment_update_11001(&$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = $storage->getQuery()
    ->condition('type', 'book')
    ->exists('field_serie_number')
    ->accessCheck(FALSE)
    ->execute();

  $migrated = 0;
  foreach ($storage->loadMultiple($nids) as $node) {
    if (!$node->hasField('field_serie_position') || !$node->get('field_serie_position')->isEmpty()) {
      continue;
    }
    $value = $node->get('field_serie_number')->value;
    if ($value === NULL || $value === '') {
      continue;
    }
    $node->set('field_serie_position', (float) $value);
    $node->save();
    $migrated++;
  }

  $oldStorage = FieldStorageConfig::loadByName('node', 'field_serie_number');
  if ($oldStorage) {
    $oldStorage->delete();
  }

  return t('Migrated @count series positions and removed field_serie_number.', [
    '@count' => $migrated,
  ]);
}
```

- [ ] **Step 2: Update the template**

In `web/themes/custom/books/templates/content/node/book/node--book.html.twig`, replace line 14:

```twig
          <span>Book {{ content.field_serie_number }} of</span>
```

with:

```twig
          <span>Book {{ content.field_serie_position }} of</span>
```

- [ ] **Step 3: Repoint the views**

In `config/sync/views.view.taxonomy_term.yml`, the sort block at lines 51-63 references the old field. Replace every occurrence of `field_serie_number` with `field_serie_position` and `node__field_serie_number` with `node__field_serie_position`:

```bash
sed -i '' \
  -e 's/node__field_serie_number/node__field_serie_position/g' \
  -e 's/field_serie_number_value/field_serie_position_value/g' \
  -e 's/field\.storage\.node\.field_serie_number/field.storage.node.field_serie_position/g' \
  -e 's/\bfield_serie_number\b/field_serie_position/g' \
  config/sync/views.view.taxonomy_term.yml config/sync/views.view.books_admin.yml
grep -rn "field_serie_number" config/sync/ || echo "no references left"
```

Expected: `no references left`.

- [ ] **Step 4: Run the update hook**

```bash
ddev drush updb -y
ddev drush cim -y
ddev drush cex -y
git status --porcelain config/sync
```

Expected: `updb` reports the migration message; `cim` applies the view changes; the `field.*.field_serie_number.*` files are gone after `cex`.

- [ ] **Step 5: Verify no references remain**

```bash
grep -rn "field_serie_number" config/sync web/modules/custom web/themes/custom || echo "clean"
```

Expected: `clean`.

- [ ] **Step 6: Commit**

```bash
git add -A config/sync web/modules/custom web/themes/custom
git commit -m "feat(books): Migrate field_serie_number to decimal field_serie_position"
```

---

### Task 5: Moods in the template, search index and facets

**Files:**
- Modify: `web/themes/custom/books/templates/content/node/book/node--book.html.twig:53-58`
- Modify: `config/sync/search_api.index.books_index.yml`
- Create: `config/sync/facets.facet.moods.yml`

**Interfaces:**
- Consumes: `field_moods` from Task 3.
- Produces: a `mood` search index field and a `moods` facet on the faceted search page.

- [ ] **Step 1: Add moods to the book template**

In `node--book.html.twig`, the genres block is currently the last row in the metadata box and has no bottom border. Replace this block:

```twig
        {% if content.field_genres | render %}
          <div class="flex justify-between py-2 text-[0.9375rem]">
            <span class="text-ink-muted uppercase text-xs tracking-wider" style="font-family: var(--font-accent);">Genres</span>
            <span class="text-ink font-medium">{{ content.field_genres }}</span>
          </div>
        {% endif %}
```

with:

```twig
        {% if content.field_genres | render %}
          <div class="flex justify-between py-2 border-b border-dotted border-stone-300 text-[0.9375rem]">
            <span class="text-ink-muted uppercase text-xs tracking-wider" style="font-family: var(--font-accent);">Genres</span>
            <span class="text-ink font-medium">{{ content.field_genres }}</span>
          </div>
        {% endif %}
        {% if content.field_moods | render %}
          <div class="flex justify-between py-2 text-[0.9375rem]">
            <span class="text-ink-muted uppercase text-xs tracking-wider" style="font-family: var(--font-accent);">Moods</span>
            <span class="text-ink font-medium">{{ content.field_moods }}</span>
          </div>
        {% endif %}
```

- [ ] **Step 2: Add the search index field**

In `config/sync/search_api.index.books_index.yml`, find the `genre:` field block and add a `mood:` block immediately after it, keeping the alphabetical ordering of the `field_settings` keys:

```yaml
  mood:
    label: 'Moods » Taxonomy term » Name'
    datasource_id: 'entity:node'
    property_path: 'field_moods:entity:name'
    type: string
    dependencies:
      config:
        - field.storage.node.field_moods
      module:
        - taxonomy
```

Also add `- field.storage.node.field_moods` to the file's top-level `dependencies.config` list, keeping it alphabetically sorted.

- [ ] **Step 3: Create the facet**

Create `config/sync/facets.facet.moods.yml` — identical to `facets.facet.genres.yml` apart from identity. Omit the `uuid` key so Drupal assigns one on import:

```bash
sed -e '/^uuid:/d' \
    -e 's/^id: genres$/id: moods/' \
    -e 's/^name: Genres$/name: Moods/' \
    -e 's/^url_alias: genres$/url_alias: moods/' \
    -e 's/^field_identifier: genre$/field_identifier: mood/' \
    config/sync/facets.facet.genres.yml > config/sync/facets.facet.moods.yml
grep -E "^(id|name|url_alias|field_identifier|uuid):" config/sync/facets.facet.moods.yml
```

Expected output: exactly four lines — `id: moods`, `name: Moods`, `url_alias: moods`, `field_identifier: mood` — and no `uuid`.

- [ ] **Step 4: Import, reindex and export**

```bash
ddev drush cim -y
ddev drush search-api:reset-tracker books_index
ddev drush search-api:index books_index
ddev drush cex -y
```

Expected: import succeeds, indexing completes, export shows the facet gaining a uuid.

- [ ] **Step 5: Verify the facet renders**

Visit the faceted search page. Expected: a "Moods" facet block alongside "Genres". It will be empty until books are synced in Task 9 — that is correct at this point.

- [ ] **Step 6: Commit**

```bash
git add -A config/sync web/themes/custom
git commit -m "feat(books): Surface moods in the book template, search index and facets"
```

---

### Task 6: Cover downloads from a supplied URL

**Files:**
- Modify: `MODULE/src/Services/CoverDownloadService.php`
- Modify: `MODULE/tests/src/Unit/Services/CoverDownloadServiceTest.php`

**Interfaces:**
- Consumes: `cover_url` produced by `HardcoverService::formatBookData()` in Task 2.
- Produces: `CoverDownloadService::downloadBookCover(string $isbn, ?string $imageUrl = NULL)` — returns the media entity, or `FALSE` when there is no usable image.

- [ ] **Step 1: Write the failing tests**

Add to `MODULE/tests/src/Unit/Services/CoverDownloadServiceTest.php`:

```php
  /**
   * Tests that a NULL image URL yields no cover rather than an error.
   *
   * @covers ::downloadBookCover
   */
  public function testDownloadBookCoverReturnsFalseWithoutUrl(): void {
    $this->httpClient->expects($this->never())->method('request');

    $this->assertFalse($this->coverDownloadService->downloadBookCover('9780765326379', NULL));
  }

  /**
   * Tests that the file extension comes from the response Content-Type.
   *
   * Hardcover asset URLs are not guaranteed to carry a usable extension.
   *
   * @covers ::buildFilename
   */
  public function testBuildFilenameUsesContentType(): void {
    $method = new \ReflectionMethod($this->coverDownloadService, 'buildFilename');

    $name = $method->invoke(
      $this->coverDownloadService,
      'https://assets.hardcover.app/edition/30455612/1deeb21c.jpeg?w=400',
      'image/jpeg',
    );

    $this->assertStringEndsWith('.jpeg', $name);
    $this->assertStringNotContainsString('?', $name);
    $this->assertStringNotContainsString('w=400', $name);
  }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/CoverDownloadServiceTest.php`
Expected: FAIL — `buildFilename` does not exist and the two-argument call is rejected.

- [ ] **Step 3: Rewrite the download path**

In `CoverDownloadService`, replace `downloadBookCover()` and `getBookCover()` and delete `buildSourceArray()` entirely:

```php
  /**
   * Gets or creates the media entity holding a book's cover.
   *
   * @param string $isbn
   *   ISBN of the book.
   * @param string|null $imageUrl
   *   Cover image URL supplied by the data source.
   *
   * @return \Drupal\Core\Entity\EntityInterface|false
   *   The media entity, or FALSE when no cover could be obtained.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function downloadBookCover(string $isbn, ?string $imageUrl = NULL) {
    if ($media = $this->getMediaByIsbn($isbn)) {
      return $media;
    }
    if (empty($imageUrl)) {
      return FALSE;
    }
    $image = $this->getBookCover($imageUrl, $isbn);
    if (!$image) {
      return FALSE;
    }
    return $this->createMedia($image, $isbn);
  }

  /**
   * Downloads a cover image and saves it as a managed file.
   *
   * @param string $image_url
   *   The URL of the image to download.
   * @param string $isbn
   *   ISBN of the book, used for logging.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The file entity, or NULL on failure.
   */
  private function getBookCover(string $image_url, string $isbn): ?EntityInterface {
    try {
      $response = $this->httpClient->request('GET', $image_url);
      $contents = $response->getBody()->getContents();
      if ($contents === '') {
        $this->loggerChannelFactory->get('CoverDownloadService')
          ->alert('Empty cover response for ISBN ' . $isbn . ' (' . $image_url . ')');
        return NULL;
      }

      $filename = $this->buildFilename($image_url, $response->getHeaderLine('Content-Type'));
      $file = $this->fileRepository->writeData($contents, 'public://book-cover/' . $filename);
      $file->setPermanent();
      $file->save();
    }
    catch (RequestException $e) {
      $this->loggerChannelFactory->get('CoverDownloadService')
        ->alert($e->getCode() . ' : ' . $e->getMessage());
      return NULL;
    }

    return $file ?? NULL;
  }

  /**
   * Builds a safe, unique filename for a downloaded cover.
   *
   * Query strings are stripped and the extension is taken from the response
   * content type, because Hardcover asset URLs do not reliably carry one.
   *
   * @param string $image_url
   *   The image URL.
   * @param string $contentType
   *   The response Content-Type header.
   *
   * @return string
   *   The filename.
   */
  protected function buildFilename(string $image_url, string $contentType): string {
    $extensions = [
      'image/jpeg' => 'jpeg',
      'image/jpg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
    ];
    $type = strtolower(trim(explode(';', $contentType)[0]));
    $extension = $extensions[$type] ?? 'jpg';

    $base = basename(parse_url($image_url, PHP_URL_PATH) ?: 'cover');
    $base = preg_replace('/\.[^.]*$/', '', $base);
    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base) ?: 'cover';

    return uniqid() . '_' . $base . '.' . $extension;
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Unit/Services/CoverDownloadServiceTest.php`
Expected: PASS. Any pre-existing test asserting the publisher-URL behaviour must be deleted, not adapted — that behaviour is gone.

- [ ] **Step 5: Lint and commit**

```bash
ddev phpcs web/modules/custom/books_book_managment
git add web/modules/custom/books_book_managment
git commit -m "refactor(books): Download covers from a supplied URL instead of guessing publisher URLs"
```

---

### Task 7: Field mapping, gap-fill mode, and getAllBooks

**Files:**
- Modify: `MODULE/src/Services/BooksUtilsService.php`
- Modify: `MODULE/tests/src/Kernel/Services/BooksUtilsServiceKernelTest.php`

**Interfaces:**
- Consumes: the formatted array from Task 2; `field_moods` and `field_serie_position` from Task 3.
- Produces:
  - `BooksUtilsService::saveBookData(string $isbn, array $data, bool $onlyFillGaps = FALSE): EntityInterface`
  - `BooksUtilsService::getAllBooks(): array`

- [ ] **Step 1: Write the failing tests**

First extend `BooksUtilsServiceKernelTest::setUp()`. Append this to the end of the existing `setUp()` body, after the current field creation:

```php
    // Vocabularies and fields the Hardcover mapping needs.
    foreach (['serie', 'genre', 'mood'] as $vid) {
      Vocabulary::create(['vid' => $vid, 'name' => ucfirst($vid)])->save();
    }

    $termFields = [
      'field_serie' => ['cardinality' => 1, 'vid' => 'serie'],
      'field_genres' => ['cardinality' => -1, 'vid' => 'genre'],
      'field_moods' => ['cardinality' => -1, 'vid' => 'mood'],
    ];
    foreach ($termFields as $fieldName => $info) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'cardinality' => $info['cardinality'],
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => 'book',
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => [$info['vid'] => $info['vid']],
            'auto_create' => TRUE,
          ],
        ],
      ])->save();
    }

    FieldStorageConfig::create([
      'field_name' => 'field_serie_position',
      'entity_type' => 'node',
      'type' => 'decimal',
      'cardinality' => 1,
      'settings' => ['precision' => 6, 'scale' => 2],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_serie_position',
      'entity_type' => 'node',
      'bundle' => 'book',
    ])->save();
```

The class already imports `Vocabulary`, `FieldStorageConfig` and `FieldConfig`, so no new `use` statements are needed.

Then add these test methods:

```php
  /**
   * Tests that series, genres and moods are saved as taxonomy terms.
   *
   * @covers ::saveBookData
   */
  public function testSaveBookDataCreatesSeriesGenresAndMoods(): void {
    $book = $this->booksUtilsService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_serie' => 'The Stormlight Archive',
      'field_serie_position' => 3.0,
      'field_genres' => ['Fantasy', 'Adventure'],
      'field_moods' => ['Adventurous', 'Emotional', 'Tense'],
    ]);

    $this->assertSame('The Stormlight Archive', $book->get('field_serie')->entity->label());
    $this->assertSame('3.00', $book->get('field_serie_position')->value);
    $this->assertCount(2, $book->get('field_genres'));
    $this->assertCount(3, $book->get('field_moods'));
  }

  /**
   * Tests that a fractional series position survives the round trip.
   *
   * @covers ::saveBookData
   */
  public function testSaveBookDataStoresFractionalPosition(): void {
    $book = $this->booksUtilsService->saveBookData('9780765326386', [
      'title' => 'Edgedancer',
      'field_serie_position' => 2.5,
    ]);

    $this->assertSame('2.50', $book->get('field_serie_position')->value);
  }

  /**
   * Tests that gap-fill mode leaves existing values alone.
   *
   * @covers ::saveBookData
   */
  public function testGapFillDoesNotOverwritePopulatedFields(): void {
    $this->booksUtilsService->saveBookData('9780765326379', [
      'title' => 'My Corrected Title',
      'field_pages' => 1243,
    ]);

    $book = $this->booksUtilsService->saveBookData('9780765326379', [
      'title' => 'Oathbringer',
      'field_pages' => 999,
      'field_excerpt' => 'Filled from Hardcover.',
    ], TRUE);

    $this->assertSame('My Corrected Title', $book->getTitle());
    $this->assertSame('1243', (string) $book->get('field_pages')->value);
    $this->assertSame('Filled from Hardcover.', $book->get('field_excerpt')->value);
  }

  /**
   * Tests that every book node is returned for the backfill.
   *
   * @covers ::getAllBooks
   */
  public function testGetAllBooksReturnsEveryBook(): void {
    $this->booksUtilsService->saveBookData('9780765326379', ['title' => 'One']);
    $this->booksUtilsService->saveBookData('9780765326386', ['title' => 'Two']);

    $this->assertCount(2, $this->booksUtilsService->getAllBooks());
  }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Kernel/Services/BooksUtilsServiceKernelTest.php`
Expected: FAIL — `getAllBooks()` undefined, and series/genre/mood values are not written.

- [ ] **Step 3: Rewrite saveBookData and add getAllBooks**

Replace `saveBookData()` in `BooksUtilsService` with:

```php
  /**
   * Saves formatted data into a book node.
   *
   * @param string $isbn
   *   ISBN-13 of the book.
   * @param array $data
   *   Formatted data keyed by field name.
   * @param bool $onlyFillGaps
   *   When TRUE, only fields that are currently empty are written, so manual
   *   corrections survive a re-sync.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The saved book node.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function saveBookData(string $isbn, array $data, bool $onlyFillGaps = FALSE): EntityInterface {
    $book = $this->getBook($isbn);

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
      if (empty($data[$field]) || !$this->shouldWrite($book, $field, $onlyFillGaps)) {
        continue;
      }
      $book->set($field, $data[$field]);
    }

    if (!empty($data['field_publisher']) && $this->shouldWrite($book, 'field_publisher', $onlyFillGaps)) {
      $book->set('field_publisher', $this->getTermByName($data['field_publisher'], 'publisher'));
    }

    if (!empty($data['field_serie']) && $this->shouldWrite($book, 'field_serie', $onlyFillGaps)) {
      $book->set('field_serie', $this->getTermByName($data['field_serie'], 'serie'));
    }

    $multiValue = [
      'field_authors' => 'author',
      'field_genres' => 'genre',
      'field_moods' => 'mood',
    ];
    foreach ($multiValue as $field => $vid) {
      if (empty($data[$field]) || !$this->shouldWrite($book, $field, $onlyFillGaps)) {
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
   * @param \Drupal\Core\Entity\EntityInterface $book
   *   The book node.
   * @param string $field
   *   The field name.
   * @param bool $onlyFillGaps
   *   Whether gap-fill mode is active.
   *
   * @return bool
   *   TRUE when the field should be written.
   */
  protected function shouldWrite(EntityInterface $book, string $field, bool $onlyFillGaps): bool {
    if (!$book->hasField($field)) {
      return FALSE;
    }
    return !$onlyFillGaps || $book->get($field)->isEmpty();
  }

  /**
   * Upserts terms by name and returns entity reference values.
   *
   * @param array $names
   *   Term names.
   * @param string $vid
   *   Vocabulary id.
   *
   * @return array
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
   * @return array
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Kernel/Services/BooksUtilsServiceKernelTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
ddev phpcs web/modules/custom/books_book_managment
git add web/modules/custom/books_book_managment
git commit -m "feat(books): Map series, genres and moods, and add gap-fill save mode"
```

---

### Task 8: Queue worker

**Files:**
- Create: `MODULE/src/Plugin/QueueWorker/HardcoverBookSync.php`
- Create: `MODULE/tests/src/Kernel/Plugin/QueueWorker/HardcoverBookSyncKernelTest.php`

**Interfaces:**
- Consumes: `books.hardcover`, `books.cover_download`, `books.books_utils`.
- Produces: queue `hardcover_book_sync`, items shaped `['nid' => int, 'isbn' => string, 'only_fill_gaps' => bool]`.

- [ ] **Step 1: Write the failing test**

Create `MODULE/tests/src/Kernel/Plugin/QueueWorker/HardcoverBookSyncKernelTest.php`:

```php
<?php

namespace Drupal\Tests\books_book_managment\Kernel\Plugin\QueueWorker;

use Drupal\books_book_managment\Exception\HardcoverRateLimitException;
use Drupal\books_book_managment\Services\HardcoverService;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for the Hardcover book sync queue worker.
 *
 * @group books_book_managment
 * @coversDefaultClass \Drupal\books_book_managment\Plugin\QueueWorker\HardcoverBookSync
 */
class HardcoverBookSyncKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'taxonomy',
    'field',
    'text',
    'file',
    'image',
    'media',
    'user',
    'isbn',
    'books_book_managment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('node', ['node_access']);
  }

  /**
   * Returns the worker with a stubbed Hardcover service.
   *
   * @param \Drupal\books_book_managment\Services\HardcoverService $hardcover
   *   The stubbed service.
   *
   * @return \Drupal\Core\Queue\QueueWorkerInterface
   *   The worker plugin.
   */
  protected function workerWith(HardcoverService $hardcover) {
    $this->container->set('books.hardcover', $hardcover);
    return $this->container->get('plugin.manager.queue_worker')
      ->createInstance('hardcover_book_sync');
  }

  /**
   * Tests that a rate limit becomes a delayed requeue, never a lost item.
   *
   * @covers ::processItem
   */
  public function testRateLimitCausesDelayedRequeue(): void {
    $hardcover = $this->createMock(HardcoverService::class);
    $hardcover->method('getFormattedBookData')
      ->willThrowException(new HardcoverRateLimitException(30));

    $worker = $this->workerWith($hardcover);

    $this->expectException(DelayedRequeueException::class);
    try {
      $worker->processItem(['nid' => 1, 'isbn' => '9780765326379', 'only_fill_gaps' => TRUE]);
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(30, $e->getDelay());
      throw $e;
    }
  }

  /**
   * Tests that a book Hardcover does not know consumes its item.
   *
   * Requeueing it forever would burn quota on a book that will never resolve.
   *
   * @covers ::processItem
   */
  public function testUnknownIsbnConsumesItem(): void {
    $hardcover = $this->createMock(HardcoverService::class);
    $hardcover->method('getFormattedBookData')->willReturn(NULL);

    $worker = $this->workerWith($hardcover);

    $worker->processItem(['nid' => 1, 'isbn' => '9780000000000', 'only_fill_gaps' => TRUE]);
    $this->assertTrue(TRUE, 'Processing returned without throwing.');
  }

}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Kernel/Plugin/QueueWorker/HardcoverBookSyncKernelTest.php`
Expected: FAIL — no `hardcover_book_sync` plugin.

- [ ] **Step 3: Write the worker**

Create `MODULE/src/Plugin/QueueWorker/HardcoverBookSync.php`:

```php
<?php

namespace Drupal\books_book_managment\Plugin\QueueWorker;

use Drupal\books_book_managment\Exception\HardcoverRateLimitException;
use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\books_book_managment\Services\CoverDownloadService;
use Drupal\books_book_managment\Services\HardcoverService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs one book from Hardcover.
 *
 * A rate limit is translated into DelayedRequeueException, which core's
 * Cron::processQueues() turns into DatabaseQueue::delayItem(). The item stays
 * in the queue and becomes claimable once the limit resets, so throttling
 * postpones work rather than dropping it.
 */
#[QueueWorker(
  id: 'hardcover_book_sync',
  title: new TranslatableMarkup('Hardcover book sync'),
  cron: ['time' => 30],
)]
class HardcoverBookSync extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a HardcoverBookSync worker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\books_book_managment\Services\HardcoverService $hardcoverService
   *   Hardcover data service.
   * @param \Drupal\books_book_managment\Services\CoverDownloadService $coverDownloadService
   *   Cover downloader service.
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Book utilities service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected HardcoverService $hardcoverService,
    protected CoverDownloadService $coverDownloadService,
    protected BooksUtilsService $booksUtilsService,
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('books.hardcover'),
      $container->get('books.cover_download'),
      $container->get('books.books_utils'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $logger = $this->loggerChannelFactory->get('HardcoverBookSync');
    $isbn = $data['isbn'] ?? NULL;
    $onlyFillGaps = $data['only_fill_gaps'] ?? TRUE;

    if (empty($isbn)) {
      $logger->error('Queue item for node @nid has no ISBN.', ['@nid' => $data['nid'] ?? 0]);
      return;
    }

    try {
      $bookData = $this->hardcoverService->getFormattedBookData($isbn);
    }
    catch (HardcoverRateLimitException $e) {
      throw new DelayedRequeueException($e->getRetryAfter(), $e->getMessage());
    }
    catch (\Exception $e) {
      $logger->warning('Transient failure for ISBN @isbn, requeueing: @message', [
        '@isbn' => $isbn,
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException($e->getMessage());
    }

    if ($bookData === NULL) {
      $logger->notice('Hardcover has no record for ISBN @isbn; leaving the book as is.', [
        '@isbn' => $isbn,
      ]);
      return;
    }

    $cover = $this->coverDownloadService->downloadBookCover($isbn, $bookData['cover_url'] ?? NULL);
    if ($cover) {
      $bookData['field_cover'] = $cover;
    }

    $this->booksUtilsService->saveBookData($isbn, $bookData, $onlyFillGaps);
    $logger->info('Synced ISBN @isbn from Hardcover.', ['@isbn' => $isbn]);
  }

}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `ddev phpunit web/modules/custom/books_book_managment/tests/src/Kernel/Plugin/QueueWorker/HardcoverBookSyncKernelTest.php`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
ddev phpcs web/modules/custom/books_book_managment
git add web/modules/custom/books_book_managment
git commit -m "feat(hardcover): Add queue worker that defers rather than drops rate-limited syncs"
```

---

### Task 9: Forms and Drush entry points

**Files:**
- Modify: `MODULE/src/Form/AddBookForm.php`
- Modify: `MODULE/src/Form/UpdateBookForm.php`
- Modify: `MODULE/src/Commands/BooksBookManagmentCommands.php`
- Modify: `MODULE/tests/src/Functional/Form/AddBookFormFunctionalTest.php`

**Interfaces:**
- Consumes: `books.hardcover`, `books.cover_download`, `books.books_utils`, queue `hardcover_book_sync`.
- Produces:
  - `BooksUtilsService::queueBooksForSync(array $nids): int` — added in Step 3 of this task, used by Step 2 and Step 4
  - `drush books:sync [--nid=] [--run]` and `drush update-cover`
  - an `/add-book` that never loses a scanned ISBN

- [ ] **Step 1: Rewrite AddBookForm's dependencies and submit handler**

Replace the constructor, `create()`, `submitForm()` and delete `mergeBookData()`:

```php
  /**
   * Constructs a AddBookForm object.
   *
   * @param \Drupal\isbn\IsbnToolsServiceInterface $isbnToolsService
   *   ISBN Tools Service.
   * @param \Drupal\books_book_managment\Services\HardcoverService $hardcoverService
   *   Hardcover data service.
   * @param \Drupal\books_book_managment\Services\CoverDownloadService $coverDownloadService
   *   Cover Downloader Service.
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Book Utilities Service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory, used when the API is rate limited.
   */
  public function __construct(
    protected IsbnToolsServiceInterface $isbnToolsService,
    protected HardcoverService $hardcoverService,
    protected CoverDownloadService $coverDownloadService,
    protected BooksUtilsService $booksUtilsService,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('isbn.isbn_service'),
      $container->get('books.hardcover'),
      $container->get('books.cover_download'),
      $container->get('books.books_utils'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $isbn = $form_state->getValue('isbn');

    try {
      $bookData = $this->hardcoverService->getFormattedBookData($isbn);
    }
    catch (HardcoverRateLimitException) {
      $book = $this->booksUtilsService->saveBookData($isbn, ['field_isbn' => $isbn]);
      $this->queueFactory->get('hardcover_book_sync')->createItem([
        'nid' => $book->id(),
        'isbn' => $isbn,
        'only_fill_gaps' => TRUE,
      ]);
      $this->messenger()->addWarning($this->t('Hardcover is rate limited right now. The book was saved and will be filled in automatically.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
      return;
    }

    if ($bookData === NULL) {
      $book = $this->booksUtilsService->saveBookData($isbn, ['field_isbn' => $isbn]);
      $this->messenger()->addWarning($this->t('Hardcover has no data for this ISBN. The book was created — please fill in the details.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
      return;
    }

    $cover = $this->coverDownloadService->downloadBookCover($isbn, $bookData['cover_url'] ?? NULL);
    if ($cover) {
      $bookData['field_cover'] = $cover;
    }

    $book = $this->booksUtilsService->saveBookData($isbn, $bookData);
    $this->messenger()->addStatus($this->t('Book has been created'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $book->id()]);
  }
```

Update the `use` statements: drop `GoogleBooksService` and `OpenLibraryService`, add `Drupal\books_book_managment\Exception\HardcoverRateLimitException`, `Drupal\books_book_managment\Services\HardcoverService` and `Drupal\Core\Queue\QueueFactory`.

- [ ] **Step 2: Rewrite UpdateBookForm to enqueue**

Replace the class body's constructor, `create()`, `buildForm()` and `submitForm()`, and delete `updateBookProcess()` and `updateBookFinished()`:

```php
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
```

- [ ] **Step 3: Add the queueing helper to BooksUtilsService**

Add to `BooksUtilsService`, and inject `Drupal\Core\Queue\QueueFactory` as a fourth constructor argument with `'@queue'` appended to the `books.books_utils` arguments in `books_book_managment.services.yml`:

```php
  /**
   * Queues book nodes for a Hardcover sync.
   *
   * @param array $nids
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
```

- [ ] **Step 4: Rewrite the Drush commands**

Replace the body of `BooksBookManagmentCommands`:

```php
  /**
   * BooksBookManagmentCommands constructor.
   *
   * @param \Drupal\books_book_managment\Services\BooksUtilsService $booksUtilsService
   *   Utils for Book management service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorkerManager
   *   Queue worker plugin manager.
   */
  public function __construct(
    private BooksUtilsService $booksUtilsService,
    private QueueFactory $queueFactory,
    private QueueWorkerManagerInterface $queueWorkerManager,
  ) {
    parent::__construct();
  }

  /**
   * Queue books for a Hardcover sync.
   *
   * @param array $options
   *   Command options.
   *
   * @option nid
   *   Sync only this node ID.
   * @option run
   *   Drain the queue immediately instead of waiting for cron.
   *
   * @usage books:sync
   *   Queue every book and let cron drain the queue.
   * @usage books:sync --run
   *   Queue every book and process them now.
   *
   * @command books:sync
   * @aliases bs
   */
  public function sync(array $options = ['nid' => NULL, 'run' => FALSE]): void {
    $nids = $options['nid'] ? [$options['nid']] : $this->booksUtilsService->getAllBooks();
    $queued = $this->booksUtilsService->queueBooksForSync($nids);

    $this->logger()->success(dt('@count book(s) queued.', ['@count' => $queued]));

    if ($options['run']) {
      $this->drainQueue();
    }
  }

  /**
   * Queue books missing a cover for a Hardcover sync.
   *
   * Covers arrive in the same request as the rest of the data, so this is a
   * normal gap-filling sync restricted to books without a cover.
   *
   * @usage update-cover
   *   Queue every book missing a cover.
   *
   * @command update-cover
   * @aliases buc
   */
  public function updateCover(): void {
    $queued = $this->booksUtilsService->queueBooksForSync(
      $this->booksUtilsService->getBooksMissingCover()
    );

    if ($queued === 0) {
      $this->logger()->warning(dt('No books without cover.'));
      return;
    }

    $this->logger()->success(dt('@count book(s) queued for cover sync.', ['@count' => $queued]));
    $this->drainQueue();
  }

  /**
   * Processes the sync queue in-process, honouring the API rate limit.
   *
   * Cron uses DelayedRequeueException to postpone throttled items; running
   * in-process there is nothing to postpone to, so this sleeps instead.
   */
  protected function drainQueue(): void {
    $queue = $this->queueFactory->get('hardcover_book_sync');
    $worker = $this->queueWorkerManager->createInstance('hardcover_book_sync');
    $processed = 0;
    $failed = 0;

    while ($item = $queue->claimItem()) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (DelayedRequeueException $e) {
        $queue->releaseItem($item);
        $this->logger()->notice(dt('Rate limited, waiting @seconds s.', [
          '@seconds' => $e->getDelay(),
        ]));
        sleep($e->getDelay());
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $failed++;
      }
      catch (\Exception $e) {
        $queue->deleteItem($item);
        $failed++;
        $this->logger()->error($e->getMessage());
      }
    }

    $this->logger()->success(dt('@processed synced, @failed failed.', [
      '@processed' => $processed,
      '@failed' => $failed,
    ]));
  }
```

Add `use` statements for `Drupal\Core\Queue\QueueFactory`, `Drupal\Core\Queue\QueueWorkerManagerInterface`, `Drupal\Core\Queue\DelayedRequeueException`, `Drupal\Core\Queue\RequeueException`, and update `drush.services.yml` to inject `@queue` and `@plugin.manager.queue_worker`.

- [ ] **Step 5: Update the functional test**

In `AddBookFormFunctionalTest`, add:

```php
  /**
   * Tests that an unknown ISBN still produces a book node.
   *
   * A scanned barcode should never be lost, so the form creates a stub the
   * user can complete by hand.
   */
  public function testUnknownIsbnCreatesStub(): void {
    $this->drupalGet('/add-book');
    $this->submitForm(['isbn' => '9780765326379'], 'Add book');

    $this->assertSession()->pageTextContains('please fill in the details');
  }
```

This test runs without a configured API token, so `getFormattedBookData()` returns `NULL` and the stub path is exercised without any network access.

- [ ] **Step 6: Run the tests**

```bash
ddev phpunit web/modules/custom/books_book_managment
```
Expected: PASS. `MissingCoverBatchTest`, `GoogleBooksServiceTest` and `OpenLibraryServiceTest` still exist at this point and may fail — they are removed in Task 10.

- [ ] **Step 7: Commit**

```bash
ddev phpcs web/modules/custom/books_book_managment
git add web/modules/custom/books_book_managment
git commit -m "feat(books): Route syncs through the queue and never drop a scanned ISBN"
```

---

### Task 10: Remove the retired sources and verify end to end

**Files:**
- Delete: `MODULE/src/Services/BookDataServiceInterface.php`, `GoogleBooksService.php`, `OpenLibraryService.php`, `MODULE/src/Batches/MissingCoverBatch.php`
- Delete: `MODULE/tests/src/Unit/Services/GoogleBooksServiceTest.php`, `OpenLibraryServiceTest.php`, `MODULE/tests/src/Unit/Batches/MissingCoverBatchTest.php`
- Modify: `MODULE/books_book_managment.services.yml`

**Interfaces:**
- Consumes: everything above.
- Produces: a module whose only data source is Hardcover.

- [ ] **Step 1: Delete the retired classes and tests**

```bash
cd /Users/gealion/Sites/perso/books.gealion.ch
git rm web/modules/custom/books_book_managment/src/Services/BookDataServiceInterface.php \
       web/modules/custom/books_book_managment/src/Services/GoogleBooksService.php \
       web/modules/custom/books_book_managment/src/Services/OpenLibraryService.php \
       web/modules/custom/books_book_managment/src/Batches/MissingCoverBatch.php \
       web/modules/custom/books_book_managment/tests/src/Unit/Services/GoogleBooksServiceTest.php \
       web/modules/custom/books_book_managment/tests/src/Unit/Services/OpenLibraryServiceTest.php \
       web/modules/custom/books_book_managment/tests/src/Unit/Batches/MissingCoverBatchTest.php
```

- [ ] **Step 2: Remove the retired service definitions**

In `MODULE/books_book_managment.services.yml`, delete the `books.google_books` and `books.open_library` blocks entirely.

- [ ] **Step 3: Verify nothing references the deleted code**

```bash
grep -rn "GoogleBooks\|OpenLibrary\|BookDataServiceInterface\|MissingCoverBatch\|google_api_key" \
  web/modules/custom web/themes/custom config/sync || echo "clean"
```

Expected: `clean`. If `google_api_key` appears in `web/sites/default/settings.local.php`, remove that line too — it is no longer read.

- [ ] **Step 4: Full verification suite**

```bash
ddev drush cr
ddev phpcs web/modules/custom/books_book_managment
ddev phpstan
ddev phpunit web/modules/custom/books_book_managment
ddev drush cim -y
```

Expected: phpcs clean, phpstan clean, all tests pass, `cim` reports no changes.

- [ ] **Step 5: Live smoke test**

```bash
NID=$(ddev drush sqlq "SELECT nid FROM node WHERE type='book' LIMIT 1")
ddev drush books:sync --nid=$NID --run
```

Then open that book's page. Expected: cover, series with a decimal position, authors, publisher, excerpt, genres and moods all populated.

Then add a new book at `/add-book` using ISBN `9780765326379`. Expected: redirect to a fully populated Oathbringer node showing series "The Stormlight Archive", position 3, and both genre and mood terms.

- [ ] **Step 6: Backfill the library**

```bash
ddev drush books:sync
ddev drush queue:list
```

Expected: `queue:list` shows `hardcover_book_sync` with the queued count. Cron drains it; `ddev drush cron` can be run repeatedly to speed this up. Re-running is safe because the sync only fills gaps.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(books): Remove Google Books and Open Library sources"
```

---

## Post-implementation notes

**Tune the genre filter against your own library.** The consensus threshold
(`TAG_CONSENSUS_RATIO = 0.10`, `TAG_MIN_COUNT = 2`) was calibrated on a
seven-book sample, and it is slightly blunt on books whose tags are thinly
spread — Oathbringer loses `Adventure` and `War`, both defensible. After the
first full backfill, review
`/admin/structure/taxonomy/manage/genre/overview`. Junk terms surviving means
lowering the ratio is not the answer; add the offender to `GENRE_STOPLIST`.
Good genres going missing means the ratio is too high — try `0.05`. Both are
one-line changes in `HardcoverService`.

Note that changing the filter does not retroactively clean the taxonomy: terms
already created stay until deleted, and because `saveBookData()` runs in
gap-fill mode a re-sync will not remove genres already attached to a node.
Cleaning up means deleting the unwanted terms in the taxonomy UI.

**DMCA policy.** Still open, per the spec. Hardcover's docs warn that public
sites displaying user-uploaded cover images should publish a takedown policy.

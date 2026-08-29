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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
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
    $this->time->method('getCurrentTime')->willReturn(1000);
  }

  /**
   * Builds the service with a given settings array.
   *
   * @param array<string, mixed> $settings
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
   * Tests that a connection failure is swallowed rather than thrown.
   *
   * ConnectException extends TransferException but not RequestException, so
   * catching only the latter let a DNS or timeout failure escape the service.
   *
   * @covers ::getBookData
   */
  public function testGetBookDataReturnsNullOnConnectionFailure(): void {
    $this->httpClient->method('request')
      ->willThrowException(new ConnectException(
        'cURL error 6: Could not resolve host: api.hardcover.app',
        new Request('POST', HardcoverService::API_ENDPOINT)
      ));

    $this->assertNull($this->buildService()->getBookData('9780765326379'));
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
   * Tests the Retry-After header on a 429 response.
   *
   * A header of "42" yields 42 seconds, an explicit "0" yields the 1 second
   * floor rather than the 60 second default (a server explicitly asking for
   * an immediate retry must not be turned into a full minute wait), and an
   * absent header falls back to 60. Each case needs its own service
   * instance: once $throttledUntil is set on an instance, a following call
   * on that same instance would short-circuit before ever making a request.
   *
   * @covers ::getBookData
   */
  public function testRetryAfterHeaderVariants(): void {
    $cases = [
      ['42', 42],
      ['0', 1],
      [NULL, 60],
    ];

    foreach ($cases as [$header, $expected]) {
      $httpClient = $this->createMock(ClientInterface::class);
      $headers = $header === NULL ? [] : ['Retry-After' => $header];
      $httpClient->method('request')
        ->willReturn(new Response(429, $headers, '{}'));

      $service = new HardcoverService(
        $httpClient,
        $this->loggerFactory,
        new Settings(['hardcover_api_token' => 'test-token']),
        $this->isbnTools,
        $this->time,
      );

      try {
        $service->getBookData('9780765326379');
        $this->fail('Expected HardcoverRateLimitException was not thrown.');
      }
      catch (HardcoverRateLimitException $e) {
        $this->assertSame($expected, $e->getRetryAfter());
      }
    }
  }

  /**
   * Tests that the throttle clears once the current time passes the deadline.
   *
   * Regression test for a frozen-clock bug: TimeInterface::getRequestTime()
   * is frozen for the life of the PHP process, so a throttle set once could
   * never clear. Task 9's Drush command drains the whole sync queue inside
   * one long-running process, catching the rate limit exception and sleeping
   * for the delay before retrying; with a frozen clock every retry would
   * throw again with the same delay, an infinite sleep loop that never
   * recovers. TimeInterface::getCurrentTime() reads the real clock, so once
   * enough time passes the service resumes making requests.
   *
   * @covers ::getBookData
   */
  public function testThrottleSelfClearsAfterDeadlinePasses(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnOnConsecutiveCalls(1000, 1000, 1100);

    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willReturn(new Response(200, ['RateLimit' => '"Free";r=0;t=30'], $this->fixture()));

    $service = new HardcoverService(
      $httpClient,
      $this->loggerFactory,
      new Settings(['hardcover_api_token' => 'test-token']),
      $this->isbnTools,
      $time,
    );

    // First call: t=1000, succeeds and exhausts the bucket, throttling until
    // t=1030.
    $this->assertIsArray($service->getBookData('9780765326379'));

    // Second call: still t=1000, still throttled, throws.
    try {
      $service->getBookData('9780765326379');
      $this->fail('Expected HardcoverRateLimitException was not thrown.');
    }
    catch (HardcoverRateLimitException $e) {
      // Expected.
    }

    // Third call: t=1100, past the t=1030 deadline, succeeds again.
    $this->assertIsArray($service->getBookData('9780765326379'));
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

  /**
   * Decodes the fixture down to the single edition array.
   *
   * @return array<string, mixed>
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

}

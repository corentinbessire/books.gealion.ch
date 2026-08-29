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
    $this->time->method('getRequestTime')->willReturn(1000);
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

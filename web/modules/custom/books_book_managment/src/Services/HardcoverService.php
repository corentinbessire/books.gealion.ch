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
 * tags and a cover URL. The service is deliberately unaware of queues: when
 * it is throttled it throws HardcoverRateLimitException and lets the caller
 * decide.
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
   *   Time service.
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

    $now = $this->time->getRequestTime();
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
      $retryAfter = max(1, (int) $response->getHeaderLine('Retry-After') ?: 60);
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

<?php

namespace Drupal\books_hardcover\Services;

use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\isbn\IsbnToolsServiceInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
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
   * @return array<string, mixed>|null
   *   The edition array, or NULL when not found or not configured.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
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

    $isbn13 = $this->normaliseToIsbn13((string) $isbn);
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
    // TransferException, not RequestException: a DNS failure, a refused
    // connection or a timeout arrives as ConnectException, which is a sibling
    // of RequestException and would otherwise escape and white-screen the
    // caller.
    catch (TransferException $e) {
      $logger->alert($e->getCode() . ' : ' . $e->getMessage());
      return NULL;
    }

    if ($response->getStatusCode() === 429) {
      $retryAfterHeader = $response->getHeaderLine('Retry-After');
      $retryAfter = is_numeric($retryAfterHeader) ? max(1, (int) $retryAfterHeader) : 60;
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
   * Normalises any accepted ISBN form to a bare ISBN-13.
   *
   * AddBookForm's validator accepts ISBN-10, and both GraphQL comparisons are
   * exact string equality, so an ISBN-10 handed straight to the query matches
   * neither _or branch and an older book silently comes back as "no data".
   *
   * @param string $isbn
   *   An ISBN-10 or ISBN-13, hyphenated or not.
   *
   * @return string
   *   The ISBN-13, or the cleaned input when it cannot be converted.
   */
  protected function normaliseToIsbn13(string $isbn): string {
    $isbn = preg_replace('/[^0-9Xx]/', '', $isbn);

    if (strlen($isbn) === 10) {
      return $this->isbnTools->convertIsbn10to13($isbn) ?? $isbn;
    }

    return $isbn;
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
   *   Current time.
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
   * @return array<int, array{name: string, remaining: int, reset: int}>
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

  /**
   * Maps a Hardcover edition record onto Drupal field values.
   *
   * Pure: no I/O, so it is unit-testable against a captured response.
   *
   * @param array<string, mixed> $edition
   *   The raw edition array from the API.
   *
   * @return array<string, mixed>
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
   * @return array<string, mixed>|null
   *   Formatted data, or NULL when Hardcover has no match.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function getFormattedBookData(string|int $isbn): ?array {
    $edition = $this->getBookData($isbn);
    return $edition ? $this->formatBookData($edition) : NULL;
  }

  /**
   * Extracts author names from an edition's contributions.
   *
   * @param array<int, mixed> $contributions
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
   * @param array<int, mixed> $bookSeries
   *   The book_series array.
   *
   * @return array{name: string|null, position: float|null}
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
   * @return array<int, array{name: string, count: int}>
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

}

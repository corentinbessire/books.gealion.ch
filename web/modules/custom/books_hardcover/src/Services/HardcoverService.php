<?php

declare(strict_types=1);

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
 * Talks to the Hardcover GraphQL API.
 *
 * Reads book data (one request returns the edition, its parent work, series
 * membership, crowd tags and a cover URL) and reads and writes the account's
 * reading log. The service is deliberately unaware of queues: when
 * it is throttled it throws HardcoverRateLimitException and lets the caller
 * decide.
 *
 * @phpstan-type ReadingLog array{user_id: int, user_books: array<int, array<string, mixed>>}
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
   * Fields fetched for a user_book wherever one is read or written.
   */
  protected const USER_BOOK_FIELDS = 'id book_id edition_id status_id book { title } edition { isbn_13 } user_book_reads(order_by: {id: asc}) { id started_at finished_at edition_id edition { isbn_13 } }';

  /**
   * The account's whole library with its reads.
   */
  protected const READING_LOG_QUERY = 'query ReadingLog { me { id user_books(limit: 5000, order_by: {id: asc}) { ' . self::USER_BOOK_FIELDS . ' } } }';

  /**
   * Editions behind a list of ISBN-13s, most popular first.
   */
  protected const EDITIONS_QUERY = 'query EditionsByIsbn($isbns: [String!]!) { editions(where: {isbn_13: {_in: $isbns}}, order_by: {users_count: desc}) { id isbn_13 book_id } }';

  /**
   * Adds a book to the library.
   */
  protected const INSERT_USER_BOOK_MUTATION = 'mutation InsertUserBook($object: UserBookCreateInput!) { insert_user_book(object: $object) { id error user_book { ' . self::USER_BOOK_FIELDS . ' } } }';

  /**
   * Updates a book in the library.
   */
  protected const UPDATE_USER_BOOK_MUTATION = 'mutation UpdateUserBook($id: Int!, $object: UserBookUpdateInput!) { update_user_book(id: $id, object: $object) { id error user_book { ' . self::USER_BOOK_FIELDS . ' } } }';

  /**
   * Adds a read.
   */
  protected const INSERT_READ_MUTATION = 'mutation InsertRead($userBookId: Int!, $dates: DatesReadInput!) { insert_user_book_read(user_book_id: $userBookId, user_book_read: $dates) { id error user_book_read { id started_at finished_at edition_id } } }';

  /**
   * Updates a read.
   */
  protected const UPDATE_READ_MUTATION = 'mutation UpdateRead($id: Int!, $dates: DatesReadInput!) { update_user_book_read(id: $id, object: $dates) { id error user_book_read { id started_at finished_at edition_id } } }';

  /**
   * Deletes a read.
   */
  protected const DELETE_READ_MUTATION = 'mutation DeleteRead($id: Int!) { delete_user_book_read(id: $id) { id error } }';

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
    $isbn13 = $this->normaliseToIsbn13((string) $isbn);
    $isbn10 = $this->isbnTools->convertIsbn13to10($isbn13) ?? '';

    $data = $this->query(self::BOOK_QUERY, ['isbn13' => $isbn13, 'isbn10' => $isbn10], 'ISBN ' . $isbn13);
    if ($data === NULL) {
      return NULL;
    }

    $editions = $data['editions'] ?? [];
    if (empty($editions)) {
      $this->loggerChannelFactory->get('HardcoverService')
        ->notice('No Hardcover data for ISBN @isbn.', ['@isbn' => $isbn13]);
      return NULL;
    }

    return reset($editions);
  }

  /**
   * Gets the account's reading log: every user_book with its reads.
   *
   * @return ReadingLog|null
   *   The user id and raw user_books rows, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function getReadingLog(): ?array {
    $data = $this->query(self::READING_LOG_QUERY, [], 'reading log');
    $me = $data['me'][0] ?? NULL;
    if (!is_array($me) || !isset($me['id'])) {
      return NULL;
    }

    return [
      'user_id' => (int) $me['id'],
      'user_books' => is_array($me['user_books'] ?? NULL) ? $me['user_books'] : [],
    ];
  }

  /**
   * Finds the Hardcover edition and book behind each ISBN-13.
   *
   * @param string[] $isbns
   *   ISBN-13 values.
   *
   * @return array<int|string, array{edition_id: int, book_id: int}>
   *   Keyed by ISBN-13 (an int key, as PHP casts digit-only strings). When
   *   several editions share an ISBN, the most popular one wins. ISBNs
   *   Hardcover does not know are absent.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function getEditionsByIsbn(array $isbns): array {
    $isbns = array_values(array_unique(array_filter(array_map(
      fn($isbn): string => $this->normaliseToIsbn13((string) $isbn),
      $isbns,
    ))));

    $map = [];
    // Chunked so one request never grows unbounded with the library.
    foreach (array_chunk($isbns, 200) as $chunk) {
      $data = $this->query(self::EDITIONS_QUERY, ['isbns' => $chunk], 'edition lookup');
      foreach ($data['editions'] ?? [] as $edition) {
        $isbn = $edition['isbn_13'] ?? NULL;
        // Ordered by users_count desc, so the first edition seen wins.
        if (is_string($isbn) && !isset($map[$isbn])) {
          $map[$isbn] = [
            'edition_id' => (int) $edition['id'],
            'book_id' => (int) $edition['book_id'],
          ];
        }
      }
    }

    return $map;
  }

  /**
   * Adds a book to the account's library.
   *
   * @param array<string, mixed> $object
   *   UserBookCreateInput fields.
   *
   * @return array<string, mixed>|null
   *   The created user_book row with its reads, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function insertUserBook(array $object): ?array {
    return $this->mutate('insert_user_book', 'user_book', self::INSERT_USER_BOOK_MUTATION, ['object' => $object]);
  }

  /**
   * Updates a book in the account's library.
   *
   * @param int $id
   *   The user_book id.
   * @param array<string, mixed> $object
   *   UserBookUpdateInput fields; only the ones sent are changed.
   *
   * @return array<string, mixed>|null
   *   The updated user_book row with its reads, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function updateUserBook(int $id, array $object): ?array {
    $variables = ['id' => $id, 'object' => $object];
    return $this->mutate('update_user_book', 'user_book', self::UPDATE_USER_BOOK_MUTATION, $variables);
  }

  /**
   * Adds a read to a book in the account's library.
   *
   * @param int $userBookId
   *   The user_book id.
   * @param array<string, mixed> $dates
   *   DatesReadInput fields, such as started_at and finished_at (Y-m-d).
   *
   * @return array<string, mixed>|null
   *   The created read, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function insertUserBookRead(int $userBookId, array $dates): ?array {
    $variables = ['userBookId' => $userBookId, 'dates' => $dates];
    return $this->mutate('insert_user_book_read', 'user_book_read', self::INSERT_READ_MUTATION, $variables);
  }

  /**
   * Updates a read; only the fields sent are changed.
   *
   * @param int $id
   *   The user_book_read id.
   * @param array<string, mixed> $dates
   *   DatesReadInput fields.
   *
   * @return array<string, mixed>|null
   *   The updated read, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function updateUserBookRead(int $id, array $dates): ?array {
    $variables = ['id' => $id, 'dates' => $dates];
    return $this->mutate('update_user_book_read', 'user_book_read', self::UPDATE_READ_MUTATION, $variables);
  }

  /**
   * Deletes a read.
   *
   * @param int $id
   *   The user_book_read id.
   *
   * @return bool
   *   TRUE when Hardcover confirmed the deletion.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  public function deleteUserBookRead(int $id): bool {
    $data = $this->query(self::DELETE_READ_MUTATION, ['id' => $id], 'delete_user_book_read');
    $result = $data['delete_user_book_read'] ?? NULL;
    $error = match (TRUE) {
      !is_array($result) => 'no result',
      !empty($result['error']) => (string) $result['error'],
      default => NULL,
    };
    if ($error !== NULL) {
      $this->loggerChannelFactory->get('HardcoverService')->alert('Hardcover delete_user_book_read failed: @error', [
        '@error' => $error,
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Runs a library mutation and returns the row it affected.
   *
   * Library mutations are Hasura actions that answer HTTP 200 even when they
   * fail; the failure is reported in their own "error" field.
   *
   * @param string $name
   *   The mutation field name.
   * @param string $rowKey
   *   The key holding the affected row in the result.
   * @param string $mutation
   *   The GraphQL document.
   * @param array<string, mixed> $variables
   *   The variables.
   *
   * @return array<string, mixed>|null
   *   The affected row, or NULL on failure.
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  protected function mutate(string $name, string $rowKey, string $mutation, array $variables): ?array {
    $data = $this->query($mutation, $variables, $name);
    $result = $data[$name] ?? NULL;

    if (!is_array($result) || !empty($result['error']) || !is_array($result[$rowKey] ?? NULL)) {
      $this->loggerChannelFactory->get('HardcoverService')->alert('Hardcover @name failed: @error', [
        '@name' => $name,
        '@error' => is_array($result) && !empty($result['error']) ? (string) $result['error'] : 'no row returned',
      ]);
      return NULL;
    }

    return $result[$rowKey];
  }

  /**
   * Sends one GraphQL request and returns its data.
   *
   * @param string $query
   *   The GraphQL document.
   * @param array<string, mixed> $variables
   *   The variables.
   * @param string $context
   *   What the request is for, used in log messages.
   *
   * @return array<string, mixed>|null
   *   The "data" member, or NULL on any failure (which is logged).
   *
   * @throws \Drupal\books_hardcover\Exception\HardcoverRateLimitException
   *   When the API rate limit is in effect.
   */
  protected function query(string $query, array $variables, string $context): ?array {
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

    try {
      $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'User-Agent' => self::USER_AGENT,
        ],
        'json' => [
          'query' => $query,
          // An empty PHP array would encode as a JSON list, which GraphQL
          // rejects as variables.
          'variables' => $variables === [] ? new \stdClass() : $variables,
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
      $logger->alert('Hardcover API returned HTTP @code for @context.', [
        '@code' => $response->getStatusCode(),
        '@context' => $context,
      ]);
      return NULL;
    }

    $this->recordRateLimitState($response, $now);

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!empty($data['errors'])) {
      $logger->alert('Hardcover API error for @context : @error', [
        '@context' => $context,
        '@error' => json_encode($data['errors']),
      ]);
      return NULL;
    }

    return is_array($data['data'] ?? NULL) ? $data['data'] : NULL;
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
  public function normaliseToIsbn13(string $isbn): string {
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

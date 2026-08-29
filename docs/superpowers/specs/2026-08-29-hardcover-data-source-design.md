# Hardcover as the sole book data source

**Date:** 2026-08-29
**Module:** `books_book_managment`
**Status:** Approved design, ready for implementation planning

## Goal

Replace Google Books and Open Library with the Hardcover GraphQL API as the only
source of book metadata and cover images. Populate three fields no current source
fills — series, genres, moods — and add moods to the site as a new vocabulary,
field, display, and facet.

## Why Hardcover

One request returns everything the site needs: edition metadata, the parent work's
description, series membership with position, crowd tags split by category, and a
cover image URL. The current setup needs two API calls plus three speculative
publisher URL guesses to get less data, and cannot fill `field_serie`,
`field_serie_number`, or `field_genres` at all.

## Scope

### In scope

- New `HardcoverService` querying `https://api.hardcover.app/v1/graphql`.
- Deletion of `BookDataServiceInterface`, `GoogleBooksService`, `OpenLibraryService`.
- Cover downloads sourced from the Hardcover edition image URL.
- New `mood` vocabulary, `field_moods` field, display, template output, and facet.
- `field_serie_number` (integer) replaced by `field_serie_position` (decimal).
- A queue-backed sync so rate limits never lose work.
- A gap-filling backfill over existing books.

### Out of scope

- A DMCA takedown policy page. Hardcover's docs warn that cover images are
  user-uploaded and that public sites displaying them should publish a takedown
  policy. books.gealion.ch is public. This is a content and legal decision for the
  site owner, tracked here so it is not forgotten, but not built as part of this work.
- Writing back to Hardcover (reading progress, shelves). Read-only for now.
- Any use of Hardcover user data. The API terms forbid third-party use of other
  users' libraries, reviews, and stats; this design touches only book and edition
  records.

## API contract

### Authentication

A Personal Access Token stored as `$settings['hardcover_api_token']` in
`settings.php`, mirroring how `google_api_key` is read today via the `settings`
service. Sent as `Authorization: Bearer <token>`. A `User-Agent` header
identifying the site is sent on every request, as the Hardcover docs request.

If the token is absent, `HardcoverService` logs a warning and returns `NULL`,
matching the current `GoogleBooksService` behaviour.

### The query

A single top-level query, which counts as one request against every rate limit
bucket. ISBN widening is handled inside the one query rather than by a second
round trip: `IsbnToolsServiceInterface::convertIsbn13to10()` derives the ISBN-10,
and both are matched with `_or`.

```graphql
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
```

`order_by: {users_count: desc}` picks the most-read edition when an ISBN somehow
matches more than one record.

### Response shape verification

`cached_tags` is a `jsonb` blob whose key casing is not documented. The first
implementation step is to run this query once against a real ISBN with a live
token and save the response as a test fixture. The mapper is then written against
that captured payload, not against an assumed shape. If `cached_tags` turns out to
be unusable, the fallback is the relational route:
`book { taggings { tag { tag tag_category { category } } } }`.

## Field mapping

| Drupal field | Hardcover source | Notes |
|---|---|---|
| `title` | `edition.title` → `book.title` | |
| `field_isbn` | `edition.isbn_13` → submitted ISBN | |
| `field_pages` | `edition.pages` | |
| `field_release` | `edition.release_date` | formatted `Y-m-d` |
| `field_publisher` | `edition.publisher.name` | upserted `publisher` term |
| `field_authors` | `contributions[].author.name` | upserted `author` terms |
| `field_excerpt` | `book.description` | |
| `field_serie` | `book_series[].series.name` | featured entry preferred, else first; upserted `serie` term |
| `field_serie_position` | that entry's `position` | decimal, fractional positions preserved |
| `field_genres` | `book.cached_tags` Genre category | all tags, uncapped; upserted `genre` terms |
| `field_moods` | `book.cached_tags` Mood category | all tags, uncapped; upserted `mood` terms |
| `field_cover` | `edition.image.url` → `book.default_cover_edition.image.url` | downloaded by `CoverDownloadService` |

Genres and moods are imported in full. Tag order follows Hardcover's `count`
descending so the most-agreed tags read first.

`BooksUtilsService::getTermByName()` already handles the upsert-by-name pattern
for publishers and authors and is reused unchanged for series, genres, and moods.

## Series position field change

`field_serie_number` is an integer. Hardcover positions are floats — novellas sit
at 1.5, 2.5 and so on — so the field is replaced rather than rounded.

Drupal cannot change a field storage type in place while data exists, so this is a
new field plus a migration:

- New `field_serie_position`, type `decimal`, precision 6, scale 2, on `node.book`.
- `hook_update_N` copies every existing `field_serie_number` value across, then
  deletes the old field storage.
- `decimal` rather than `float` so values are exact and sort predictably.

Consumers to update, all of which currently reference `field_serie_number`:

- `core.entity_form_display.node.book.default`
- `core.entity_view_display.node.book.default`
- `core.entity_view_display.node.book.teaser`
- `views.view.taxonomy_term` — sort on `field_serie_number_value`
- `views.view.books_admin`
- `templates/content/node/book/node--book.html.twig` line 14

## Moods

`field_genres` already exists, already renders in `node--book.html.twig`, and is
already exposed as `facets.facet.genres`. Moods get the parallel set:

- `taxonomy.vocabulary.mood`
- `field.storage.node.field_moods` and `field.field.node.book.field_moods` —
  entity reference to `mood`, unlimited cardinality, auto-create on
- form display and default view display entries, mirroring `field_genres`
- a `field_moods` block in `node--book.html.twig` next to genres
- `search_api.index.books_index` field `field_moods:entity:name`
- `facets.facet.moods`, mirroring `facets.facet.genres`

Config is created with `drush field:create` and exported with `drush cex` rather
than hand-written, so UUIDs and dependencies are generated correctly.

## Architecture

```
AddBookForm ──▶ HardcoverService::getFormattedBookData($isbn)
                     │  one GraphQL POST
                     ▼
               formatted array (field_* keys + cover_url)
                     │
                     ├──▶ CoverDownloadService::downloadBookCover($isbn, $coverUrl)
                     ▼
               BooksUtilsService::saveBookData($isbn, $data, $onlyFillGaps)

UpdateBookForm ─┐
drush books:sync ├──▶ queue 'hardcover_book_sync' ──▶ HardcoverBookSync worker
drush update-cover ─┘                                        │
                                                             └─▶ same path as above
```

### `HardcoverService`

```php
public function getBookData(string|int $isbn): ?array;          // raw edition array
public function formatBookData(array $edition): array;          // field_* array + cover_url
public function getFormattedBookData(string|int $isbn): ?array;
```

`formatBookData()` is pure — it takes the decoded edition array and returns the
formatted array with no I/O — so the mapping is unit-testable against the captured
fixture without mocking HTTP. This matches the shape the two outgoing services
already had.

The service knows nothing about queues. When it is rate limited it throws a
`HardcoverRateLimitException` carrying the number of seconds to wait; callers
decide what that means for them.

### `CoverDownloadService`

Signature becomes `downloadBookCover(string $isbn, ?string $imageUrl = NULL)`.

- `buildSourceArray()` and the three hardcoded publisher URL patterns are deleted.
- The existing reuse-media-by-ISBN check, managed file write, and media creation
  are unchanged.
- A `NULL` or empty URL returns `FALSE` — no cover, fill it in by hand.
- Filenames are derived from the URL path only, with query strings stripped and an
  extension forced from the response `Content-Type`, since Hardcover image URLs
  are not guaranteed to end in `.jpg`.

### `BooksUtilsService`

`saveBookData(string $isbn, array $data, bool $onlyFillGaps = FALSE)`.

When `$onlyFillGaps` is `TRUE`, a field is written only if it is currently empty
on the node. Manual corrections survive, and re-running is safe. The existing
callers pass `FALSE` and keep today's overwrite behaviour.

New mappings for `field_serie`, `field_serie_position`, `field_genres`,
`field_moods` alongside the existing publisher and author handling.

A `getAllBooks()` method is added next to the existing `getBooksMissingCover()`,
returning every book nid for the backfill to enqueue.

## Rate limiting and the queue

Free tier is 5,000 requests/day, 60/minute, burst 10. A backfill over the whole
library exceeds the per-minute rate immediately, so syncing runs through the
Drupal Queue API rather than a batch, and a rate limit delays work instead of
losing it.

### Detection

Hardcover returns IETF-draft `RateLimit` headers on every response — for example
`RateLimit: "Free";r=8;t=42`, where `r` is requests remaining and `t` is seconds
until the bucket resets. The legacy `X-RateLimit-*` headers are documented as
deprecated and are not used.

`HardcoverService` throws `HardcoverRateLimitException` when either:

- the response is `429` — delay taken from the `Retry-After` header, or
- the parsed `RateLimit` header shows `r=0` — delay taken from `t`.

### Worker

`Drupal\books_book_managment\Plugin\QueueWorker\HardcoverBookSync`, declared with
the Drupal 11 attribute:

```php
#[QueueWorker(
  id: 'hardcover_book_sync',
  title: new TranslatableMarkup('Hardcover book sync'),
  cron: ['time' => 30],
)]
```

Item payload: `['nid' => int, 'isbn' => string, 'only_fill_gaps' => bool]`.

Exception handling, all verified against Drupal 11.3 core:

| Condition | Worker throws | Effect |
|---|---|---|
| Per-minute limit hit | `DelayedRequeueException($delay)` | `Cron::processQueues()` calls `DatabaseQueue::delayItem()`; the item stays queued and becomes claimable after the delay |
| Daily limit exhausted | `SuspendQueueException` | the whole queue stops for this cron run; every remaining item stays queued |
| Transient network or 5xx error | `RequeueException` | item returns to the queue for the next run |
| ISBN genuinely not found | nothing | logged, item consumed, node left as a stub |
| Node or ISBN missing | nothing | logged as a failure, item consumed |

Nothing is lost in any of these paths. `DatabaseQueue` implements
`DelayableQueueInterface`, which is what makes the delay path work.

### Entry points

- **`drush books:sync`** — enqueues every book, or `--nid=` for one. Cron drains
  the queue by default; `--run` drains immediately in-process, sleeping on a rate
  limit rather than deferring to cron.
- **`UpdateBookForm`** — broadened from covers-only to a full gap-filling sync.
  Enqueues and reports how many books were queued, instead of running a batch.
- **`drush update-cover`** — kept for muscle memory. Because the cover URL now
  arrives in the same request as everything else, a cover-only path would cost the
  same as a full sync, so this enqueues a gap-filling sync for books missing a
  cover. `MissingCoverBatch` is removed.
- **`AddBookForm`** — stays synchronous, because adding a book should give
  immediate feedback and costs one request. On `HardcoverRateLimitException` it
  falls back to the stub path below and enqueues the node for enrichment.

### Backfill semantics

Gap-filling only. Hardcover never overwrites a value already on a node. No
`--overwrite` flag is built; if it is wanted later, it is a flag on an existing
parameter.

## Add-book behaviour when Hardcover has no match

The single query already widens across ISBN-13 and ISBN-10 and reads the parent
work, so there is no second lookup to try. When it still returns nothing:

1. A book node is created with the ISBN as its title and `field_isbn` set.
2. The user is redirected to it with a message saying Hardcover had no data and
   the details need filling in by hand.

This is a deliberate change from today's behaviour, which creates nothing and
shows a warning. A scanned barcode should never be lost.

## Deletions

| File | Reason |
|---|---|
| `src/Services/BookDataServiceInterface.php` | one implementation remains; the abstraction is speculative |
| `src/Services/GoogleBooksService.php` | replaced |
| `src/Services/OpenLibraryService.php` | replaced |
| `src/Batches/MissingCoverBatch.php` | replaced by the queue |
| `tests/src/Unit/Services/GoogleBooksServiceTest.php` | tests deleted code |
| `tests/src/Unit/Services/OpenLibraryServiceTest.php` | tests deleted code |
| `tests/src/Unit/Batches/MissingCoverBatchTest.php` | tests deleted code |

Also removed: the `books.google_books` and `books.open_library` service
definitions, and the `google_api_key` setting reference.

## Testing

Following the module's existing test layout.

**Unit**

- `HardcoverServiceTest` — against the captured fixture: successful mapping;
  ISBN-10 fallback match; empty `editions` array; missing token; `429` raising
  `HardcoverRateLimitException` with the `Retry-After` delay; `RateLimit: r=0`
  raising the same with the `t` delay; malformed `cached_tags` degrading to no
  genres or moods rather than fataling.
- `CoverDownloadServiceTest` — updated for the new signature; `NULL` URL returns
  `FALSE`; extension derived from `Content-Type`.

**Kernel**

- `BooksUtilsServiceKernelTest` — series, genre, and mood term upserts; uncapped
  tag import; gap-fill mode leaves populated fields untouched and fills empty ones.
- `HardcoverBookSyncKernelTest` — a rate limit leaves the item in the queue with a
  delay rather than losing it; a not-found ISBN consumes the item; a transient
  error requeues it.

**Functional**

- `AddBookFormFunctionalTest` — stub node created and redirected to when Hardcover
  returns no match.

## Verification

- `ddev phpcs web/modules/custom/books_book_managment` clean
- `ddev phpstan` clean
- `ddev phpunit` green
- `ddev drush cim` applies the new config without error
- One real `/add-book` run against a live token producing a node with cover,
  series, genres, and moods populated

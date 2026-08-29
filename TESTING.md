# Testing Strategy

This document describes the full testing strategy for the Books project, covering all levels of the testing pyramid.

## Quick Reference

```bash
# Unit tests (fast, no DB)
ddev phpunit --testsuite unit

# Kernel tests (requires installed site)
ddev phpunit --testsuite kernel

# Functional tests (requires installed site)
ddev phpunit --testsuite functional

# All PHPUnit tests
ddev phpunit

# Cypress E2E tests (requires running DDEV site)
ddev cy:test

# Static analysis
ddev phpstan analyse
ddev phpcs
```

## Testing Pyramid

### Level 1: Static Analysis

**PHPCS** enforces Drupal coding standards. Configuration lives in `phpcs.xml`.

```bash
ddev phpcs                    # Run against all custom code
ddev phpcbf                   # Auto-fix violations
```

**PHPStan** performs static type analysis at level 6. Configuration lives in `phpstan.neon` with a baseline in `phpstan-baseline.neon`.

```bash
ddev phpstan analyse          # Run analysis
```

To update the baseline after fixing errors:

```bash
ddev exec vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```

### Level 2: Unit Tests

Unit tests are fast, isolated, and require no Drupal bootstrap or database. They mock all dependencies.

**Location:** `web/modules/custom/*/tests/src/Unit/`

| Test file | Class under test | What it covers |
|-----------|-----------------|----------------|
| `HardcoverServiceTest` | `HardcoverService` | GraphQL calls against a captured fixture, ISBN-10 conversion, rate limit header parsing and throttling, connection failures, tag/genre/mood mapping |
| `CoverDownloadServiceTest` | `CoverDownloadService` | Source URL building, media query/creation, HTTP error handling |
| `BooksUtilsServiceTest` | `BooksUtilsService` | Book load/create, term upsert, missing cover query, queueing (skips non-books and books with no ISBN) |
| `AddBookFormTest` | `AddBookForm` | Form ID, successful save, and the stub-and-enqueue paths for a rate limit, a connection failure and an unknown ISBN |
| `BooksBookManagmentCommandsTest` | `BooksBookManagmentCommands` | `drainQueue()` exception handling: attempt cap, item release rather than deletion, daily-limit bail-out |
| `ActivityControllerTest` | `ActivityController` | Activity update logic, bundle validation, status query |

```bash
ddev phpunit --testsuite unit
```

### Level 3: Kernel Tests

Kernel tests boot Drupal's service container and use a real database. They validate service wiring, entity operations, and database interactions.

**Location:** `web/modules/custom/*/tests/src/Kernel/`

| Test file | What it covers |
|-----------|----------------|
| `BooksUtilsServiceKernelTest` | Service container wiring, book create/load with real DB, term upsert, missing cover query, gap-fill behaviour (a rescan never renames a known book) |
| `HardcoverBookSyncKernelTest` | Queue worker: a rate limit becomes a DelayedRequeueException so the item is postponed, an unknown ISBN consumes the item |
| `CoverDownloadServiceKernelTest` | Service instantiation, media query against real DB |
| `ActivityPresaveTest` | `books_activity_node_presave()` hook sets title from book reference |

```bash
ddev phpunit --testsuite kernel
```

### Level 4: Functional Tests

Functional tests run full HTTP request/response cycles including routing, permissions, and form submissions.

**Location:** `web/modules/custom/*/tests/src/Functional/`

| Test file | What it covers |
|-----------|----------------|
| `AddBookFormFunctionalTest` | Form access (anonymous denied, authenticated allowed), ISBN validation, stub creation for an ISBN Hardcover does not know |

**Note:** `ActivityControllerFunctionalTest` was not included because `ActivityController::create()` has a bug (passes 3 of 4 required constructor args). Fix the controller first, then add functional tests for activity routes.

```bash
ddev phpunit --testsuite functional
```

### Level 5: E2E Tests (Cypress)

End-to-end tests run in a real browser against a running DDEV site. They test critical user journeys.

**Location:** `tests/cypress/e2e/`

| Spec file | User journey |
|-----------|-------------|
| `smoke.cy.js` | Homepage loads, navigation works, anonymous/authenticated views differ |
| `add-book.cy.js` | Login, navigate to /add-book, submit ISBN, verify book created |
| `activity-lifecycle.cy.js` | Start reading, finish activity, abandon activity |
| `search.cy.js` | Search for a book, verify results appear |

```bash
ddev cy:test                  # Run headless
ddev cy:install               # Install Cypress dependencies (first time)
```

### Level 6: CI Pipeline

The GitHub Actions pipeline (`.github/workflows/ci.yml`) runs these jobs:

| Job | Dependencies | What it runs |
|-----|-------------|-------------|
| `composer` | — | Composer install, upload vendor artifact |
| `npm` | — | Theme build |
| `phpcs` | `composer` | PHP CodeSniffer (Drupal + DrupalPractice standards) |
| `phpstan` | `composer` | PHPStan level 6 with baseline |
| `phpunit-unit` | `composer` | Unit tests (no DB needed) |
| `phpunit-kernel` | `composer` | Kernel tests (MariaDB service container) |
| `phpunit-functional` | `composer` | Functional tests (MariaDB + PHP built-in server) |

Cypress E2E tests are run locally or in a dedicated nightly workflow (too slow for every PR).

## Writing New Tests

### Unit test template

```php
<?php

namespace Drupal\Tests\MODULE\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * @group MODULE
 * @coversDefaultClass \Drupal\MODULE\ClassName
 */
class ClassNameTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    // Create mocks, instantiate service.
  }

  public function testSomething(): void {
    // Arrange, Act, Assert.
  }

}
```

### Kernel test template

```php
<?php

namespace Drupal\Tests\MODULE\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group MODULE
 */
class SomethingKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'node', 'MODULE'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'node']);
  }

}
```

### Cypress spec template

```javascript
describe('Feature Name', () => {
  beforeEach(() => {
    cy.drupalLogin('admin');
  });

  it('does something', () => {
    cy.visit('/path');
    cy.get('selector').should('be.visible');
  });
});
```

## Configuration Files

| File | Purpose |
|------|---------|
| `phpunit.xml.dist` | PHPUnit config with unit/kernel/functional suites and DDEV env vars |
| `phpstan.neon` | PHPStan level 6 config with Drupal-specific ignores |
| `phpstan-baseline.neon` | Baseline for existing PHPStan errors (allows incremental improvement) |
| `phpcs.xml` | PHPCS config for Drupal + DrupalPractice standards |
| `tests/cypress.config.js` | Cypress E2E config |
| `tests/package.json` | Cypress dependencies |

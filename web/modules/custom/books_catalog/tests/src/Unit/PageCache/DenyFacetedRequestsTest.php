<?php

declare(strict_types=1);

namespace Drupal\Tests\books_catalog\Unit\PageCache;

use Drupal\books_catalog\PageCache\DenyFacetedRequests;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that faceted URLs are kept out of the page caches.
 */
#[CoversClass(DenyFacetedRequests::class)]
#[Group('books_catalog')]
final class DenyFacetedRequestsTest extends UnitTestCase {

  /**
   * Checks the policy result for a given URL.
   *
   * @param string $uri
   *   The requested URI.
   * @param string|null $expected
   *   The expected policy result.
   */
  #[DataProvider('providerUris')]
  public function testCheck(string $uri, ?string $expected): void {
    $policy = new DenyFacetedRequests();
    $this->assertSame($expected, $policy->check(Request::create($uri)));
  }

  /**
   * Provides URIs and the expected policy result.
   *
   * @return array<string, array{string, string|null}>
   *   Test cases.
   */
  public static function providerUris(): array {
    return [
      'plain listing' => ['/books', NULL],
      'pager only' => ['/books?page=2', NULL],
      'one facet' => [
        '/books?f%5B0%5D=authors%3ARobin%20Hobb',
        RequestPolicyInterface::DENY,
      ],
      'several facets' => [
        '/books?f[0]=authors:Robin%20Hobb&f[1]=genres:Dystopia&page=1',
        RequestPolicyInterface::DENY,
      ],
    ];
  }

}

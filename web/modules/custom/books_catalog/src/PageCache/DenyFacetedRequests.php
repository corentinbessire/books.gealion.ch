<?php

declare(strict_types=1);

namespace Drupal\books_catalog\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps faceted listing URLs out of the page caches.
 *
 * Every combination of facet filters (?f[0]=…&f[1]=…) is a distinct URL.
 * Crawlers walk those combinations without end, and caching each one grew
 * the cache_page table to 21 GB between two cron runs, which made the
 * deploy's database dump time out. Filtered listings are cheap to rebuild,
 * so they are simply not cached as whole pages.
 */
final class DenyFacetedRequests implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return $request->query->has('f') ? self::DENY : NULL;
  }

}

<?php

namespace Drupal\books_hardcover\Exception;

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

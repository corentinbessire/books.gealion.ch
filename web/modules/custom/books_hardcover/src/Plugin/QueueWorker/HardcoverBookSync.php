<?php

declare(strict_types=1);

namespace Drupal\books_hardcover\Plugin\QueueWorker;

use Drupal\books_hardcover\Exception\HardcoverRateLimitException;
use Drupal\books_catalog\Services\BookService;
use Drupal\books_cover\Services\CoverDownloadService;
use Drupal\books_hardcover\Services\HardcoverService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Syncs one book from Hardcover.
 *
 * A rate limit is translated into DelayedRequeueException, which core's
 * Cron::processQueues() turns into DatabaseQueue::delayItem(). The item stays
 * in the queue and becomes claimable once the limit resets, so throttling
 * postpones work rather than dropping it.
 */
#[QueueWorker(
  id: 'hardcover_book_sync',
  title: new TranslatableMarkup('Hardcover book sync'),
  cron: ['time' => 30],
)]
class HardcoverBookSync extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a HardcoverBookSync worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\books_hardcover\Services\HardcoverService $hardcoverService
   *   Hardcover data service.
   * @param \Drupal\books_cover\Services\CoverDownloadService $coverDownloadService
   *   Cover downloader service.
   * @param \Drupal\books_catalog\Services\BookService $bookService
   *   Book utilities service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected HardcoverService $hardcoverService,
    protected CoverDownloadService $coverDownloadService,
    protected BookService $bookService,
    protected LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('books_hardcover.client'),
      $container->get('books_cover.downloader'),
      $container->get('books_catalog.books'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $logger = $this->loggerChannelFactory->get('HardcoverBookSync');
    $isbn = $data['isbn'] ?? NULL;
    $onlyFillGaps = $data['only_fill_gaps'] ?? TRUE;

    if (empty($isbn)) {
      $logger->error('Queue item for node @nid has no ISBN.', ['@nid' => $data['nid'] ?? 0]);
      return;
    }

    try {
      $bookData = $this->hardcoverService->getFormattedBookData($isbn);
    }
    catch (HardcoverRateLimitException $e) {
      throw new DelayedRequeueException($e->getRetryAfter(), $e->getMessage());
    }
    catch (\Exception $e) {
      $logger->warning('Transient failure for ISBN @isbn, requeueing: @message', [
        '@isbn' => $isbn,
        '@message' => $e->getMessage(),
      ]);
      throw new RequeueException($e->getMessage());
    }

    if ($bookData === NULL) {
      $logger->notice('Hardcover has no record for ISBN @isbn; leaving the book as is.', [
        '@isbn' => $isbn,
      ]);
      return;
    }

    $cover = $this->coverDownloadService->downloadBookCover($isbn, $bookData['cover_url'] ?? NULL);
    if ($cover) {
      $bookData['field_cover'] = $cover;
    }

    $this->bookService->saveBookData($isbn, $bookData, $onlyFillGaps);
    $logger->info('Synced ISBN @isbn from Hardcover.', ['@isbn' => $isbn]);
  }

}

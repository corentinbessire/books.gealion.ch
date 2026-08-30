<?php

namespace Drupal\Tests\books_book_managment\Unit\Services;

use Drupal\books_book_managment\Services\CoverDownloadService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

/**
 * Unit tests for CoverDownloadService.
 *
 * @group books_book_managment
 * @coversDefaultClass \Drupal\books_book_managment\Services\CoverDownloadService
 */
class CoverDownloadServiceTest extends UnitTestCase {

  /**
   * The HTTP client mock.
   *
   * @var \GuzzleHttp\ClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The logger factory mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The logger channel mock.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The file repository mock.
   *
   * @var \Drupal\file\FileRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileRepository;

  /**
   * The service under test.
   *
   * @var \Drupal\books_book_managment\Services\CoverDownloadService
   */
  protected $coverDownloadService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->fileRepository = $this->createMock(FileRepositoryInterface::class);

    $this->loggerFactory->expects($this->any())
      ->method('get')
      ->willReturn($this->logger);

    $this->coverDownloadService = new CoverDownloadService(
      $this->httpClient,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->fileRepository
    );
  }

  /**
   * Tests getMediaByIsbn() returns existing media.
   *
   * @covers ::getMediaByIsbn
   */
  public function testGetMediaByIsbnFound(): void {
    $isbn = '9780142437247';
    $mediaEntity = $this->createMock(EntityInterface::class);

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([1]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('load')->with(1)->willReturn($mediaEntity);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $method = new \ReflectionMethod(CoverDownloadService::class, 'getMediaByIsbn');
    $result = $method->invoke($this->coverDownloadService, $isbn);

    $this->assertSame($mediaEntity, $result);
  }

  /**
   * Tests getMediaByIsbn() returns FALSE when no media found.
   *
   * @covers ::getMediaByIsbn
   */
  public function testGetMediaByIsbnNotFound(): void {
    $isbn = '9780142437247';

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $method = new \ReflectionMethod(CoverDownloadService::class, 'getMediaByIsbn');
    $result = $method->invoke($this->coverDownloadService, $isbn);

    $this->assertFalse($result);
  }

  /**
   * Tests createMedia() creates a media entity.
   *
   * @covers ::createMedia
   */
  public function testCreateMedia(): void {
    $isbn = '9780142437247';
    $image = $this->createMock(EntityInterface::class);
    $media = $this->createMock(ContentEntityInterface::class);

    $media->expects($this->exactly(2))
      ->method('set');
    $media->expects($this->once())->method('save');

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('create')
      ->with(['bundle' => 'book_cover'])
      ->willReturn($media);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $method = new \ReflectionMethod(CoverDownloadService::class, 'createMedia');
    $result = $method->invoke($this->coverDownloadService, $image, $isbn);

    $this->assertSame($media, $result);
  }

  /**
   * Tests downloadBookCover() returns existing media without HTTP calls.
   *
   * @covers ::downloadBookCover
   */
  public function testDownloadBookCoverExistingMedia(): void {
    $isbn = '9780142437247';
    $mediaEntity = $this->createMock(EntityInterface::class);

    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([42]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('load')->with(42)->willReturn($mediaEntity);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    // HTTP client should NOT be called when media already exists.
    $this->httpClient->expects($this->never())->method('request');

    $result = $this->coverDownloadService->downloadBookCover($isbn);
    $this->assertSame($mediaEntity, $result);
  }

  /**
   * Tests downloadBookCover() returns FALSE when the download fails.
   *
   * @covers ::downloadBookCover
   */
  public function testDownloadBookCoverReturnsFalseWhenDownloadFails(): void {
    $isbn = '9780142437247';

    // No existing media.
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    // The single supplied URL fails to download.
    $this->httpClient->expects($this->once())
      ->method('request')
      ->willThrowException(
        new RequestException('Not found', new Request('GET', 'test'))
      );

    $result = $this->coverDownloadService->downloadBookCover($isbn, 'https://assets.hardcover.app/edition/1/cover.jpeg');
    $this->assertFalse($result);
  }

  /**
   * Tests that a NULL image URL yields no cover rather than an error.
   *
   * @covers ::downloadBookCover
   */
  public function testDownloadBookCoverReturnsFalseWithoutUrl(): void {
    // No existing media, so downloadBookCover() falls through to the
    // missing-URL check.
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('condition')->willReturnSelf();
    $query->expects($this->once())->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('media')
      ->willReturn($storage);

    $this->httpClient->expects($this->never())->method('request');

    $this->assertFalse($this->coverDownloadService->downloadBookCover('9780765326379', NULL));
  }

  /**
   * Tests that the file extension comes from the response Content-Type.
   *
   * Hardcover asset URLs are not guaranteed to carry a usable extension.
   *
   * @covers ::buildFilename
   */
  public function testBuildFilenameUsesContentType(): void {
    $method = new \ReflectionMethod($this->coverDownloadService, 'buildFilename');

    $name = $method->invoke(
      $this->coverDownloadService,
      'https://assets.hardcover.app/edition/30455612/1deeb21c.jpeg?w=400',
      'image/jpeg',
    );

    $this->assertStringEndsWith('.jpeg', $name);
    $this->assertStringNotContainsString('?', $name);
    $this->assertStringNotContainsString('w=400', $name);
  }

}

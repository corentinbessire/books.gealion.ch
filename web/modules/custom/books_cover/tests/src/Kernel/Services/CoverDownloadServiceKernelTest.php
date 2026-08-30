<?php

declare(strict_types=1);

namespace Drupal\Tests\books_cover\Kernel\Services;

use Drupal\books_cover\Services\CoverDownloadService;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for CoverDownloadService.
 *
 * @group books_cover
 * @coversDefaultClass \Drupal\books_cover\Services\CoverDownloadService
 */
class CoverDownloadServiceKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'media',
    'image',
    'file',
    'field',
    'user',
    'isbn',
    'books_cover',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\books_cover\Services\CoverDownloadService
   */
  protected $coverDownloadService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'media', 'file', 'field']);

    $this->coverDownloadService = $this->container->get('books_cover.downloader');
  }

  /**
   * Tests service instantiation from the container.
   */
  public function testServiceInstantiation(): void {
    $this->assertInstanceOf(CoverDownloadService::class, $this->coverDownloadService);
  }

  /**
   * Tests getMediaByIsbn() returns FALSE for non-existent ISBN.
   *
   * @covers ::getMediaByIsbn
   */
  public function testGetMediaByIsbnReturnsEmptyForUnknownIsbn(): void {
    $method = new \ReflectionMethod(get_class($this->coverDownloadService), 'getMediaByIsbn');
    $result = $method->invoke($this->coverDownloadService, '9780000000000');
    $this->assertFalse($result);
  }

}

<?php

namespace Drupal\books_book_managment\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Custom Service to Download BookCover.
 */
class CoverDownloadService {

  /**
   * CoverDownloadService constructor.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Guzzle Client Interface Service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Drupal Logger Channel Factory Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Drupal Entity Type Manager service.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   Drupal File Repository System Service.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerChannelFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * Gets or creates the media entity holding a book's cover.
   *
   * @param string $isbn
   *   ISBN of the book.
   * @param string|null $imageUrl
   *   Cover image URL supplied by the data source.
   *
   * @return \Drupal\Core\Entity\EntityInterface|false
   *   The media entity, or FALSE when no cover could be obtained.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function downloadBookCover(string $isbn, ?string $imageUrl = NULL) {
    if ($media = $this->getMediaByIsbn($isbn)) {
      return $media;
    }
    if (empty($imageUrl)) {
      return FALSE;
    }
    $image = $this->getBookCover($imageUrl, $isbn);
    if (!$image) {
      return FALSE;
    }
    return $this->createMedia($image, $isbn);
  }

  /**
   * Downloads a cover image and saves it as a managed file.
   *
   * @param string $image_url
   *   The URL of the image to download.
   * @param string $isbn
   *   ISBN of the book, used for logging.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The file entity, or NULL on failure.
   */
  private function getBookCover(string $image_url, string $isbn): ?EntityInterface {
    try {
      $response = $this->httpClient->request('GET', $image_url);
      $contents = $response->getBody()->getContents();
      if ($contents === '') {
        $this->loggerChannelFactory->get('CoverDownloadService')
          ->alert('Empty cover response for ISBN ' . $isbn . ' (' . $image_url . ')');
        return NULL;
      }

      $filename = $this->buildFilename($image_url, $response->getHeaderLine('Content-Type'));
      $file = $this->fileRepository->writeData($contents, 'public://book-cover/' . $filename);
      $file->setPermanent();
      $file->save();
    }
    catch (RequestException $e) {
      $this->loggerChannelFactory->get('CoverDownloadService')
        ->alert($e->getCode() . ' : ' . $e->getMessage());
      return NULL;
    }

    return $file;
  }

  /**
   * Builds a safe, unique filename for a downloaded cover.
   *
   * Query strings are stripped and the extension is taken from the response
   * content type, because Hardcover asset URLs do not reliably carry one.
   *
   * @param string $image_url
   *   The image URL.
   * @param string $contentType
   *   The response Content-Type header.
   *
   * @return string
   *   The filename.
   */
  protected function buildFilename(string $image_url, string $contentType): string {
    $extensions = [
      'image/jpeg' => 'jpeg',
      'image/jpg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
    ];
    $type = strtolower(trim(explode(';', $contentType)[0]));
    $extension = $extensions[$type] ?? 'jpg';

    $base = basename(parse_url($image_url, PHP_URL_PATH) ?: 'cover');
    $base = preg_replace('/\.[^.]*$/', '', $base);
    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base) ?: 'cover';

    return uniqid() . '_' . $base . '.' . $extension;
  }

  /**
   * Create the Media Entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $image
   *   Image File Entity.
   * @param string $isbn
   *   ISBN of The Book of the Cover.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The Media Entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createMedia(
    ?EntityInterface $image,
    string $isbn,
  ) {
    $media = $this->entityTypeManager->getStorage('media')
      ->create(['bundle' => 'book_cover']);
    $media->set('name', $isbn);
    $media->set('field_media_image', $image);
    $media->save();
    return $media;
  }

  /**
   * Look for an existing Media Entity for given ISBN.
   *
   * @param string $isbn
   *   ISBN to look for.
   *
   * @return \Drupal\Core\Entity\EntityInterface|false|null
   *   Media entity if exists.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException * *   *
   *     *   *
   */
  protected function getMediaByIsbn(string $isbn) {
    $result = $this->entityTypeManager->getStorage('media')->getQuery()
      ->condition('name', $isbn)
      ->accessCheck()
      ->execute();
    return (empty($result)) ? FALSE : $this->entityTypeManager->getStorage('media')
      ->load(reset($result));
  }

}

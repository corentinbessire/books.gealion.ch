<?php

namespace Drupal\Tests\books_activity\Unit\Controller;

use Drupal\books_activity\Controller\ActivityController;
use Drupal\books_book_managment\Services\BooksUtilsService;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\isbn\IsbnToolsService;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for ActivityController.
 *
 * @group books_activity
 * @coversDefaultClass \Drupal\books_activity\Controller\ActivityController
 */
class ActivityControllerTest extends UnitTestCase {

  /**
   * The messenger mock.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * The books utils service mock.
   *
   * @var \Drupal\books_book_managment\Services\BooksUtilsService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $booksUtilsService;

  /**
   * The ISBN tools service mock.
   *
   * @var \Drupal\isbn\IsbnToolsService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $isbnToolsService;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The controller under test.
   *
   * @var \Drupal\books_activity\Controller\ActivityController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->booksUtilsService = $this->createMock(BooksUtilsService::class);
    $this->isbnToolsService = $this->createMock(IsbnToolsService::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $requestStack = $this->createMock(RequestStack::class);

    $stringTranslation = $this->createMock(TranslationInterface::class);
    $stringTranslation->method('translateString')->willReturnArgument(0);

    // Set up the container for ControllerBase dependencies.
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->willReturnMap([
        ['entity_type.manager', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->entityTypeManager],
        ['messenger', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->messenger],
        ['string_translation', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $stringTranslation],
      ]);
    \Drupal::setContainer($container);

    $this->controller = new ActivityController(
      $this->entityTypeManager,
      $this->messenger,
      $this->booksUtilsService,
      $this->isbnToolsService,
      $requestStack
    );
  }

  /**
   * Tests updateActivity() rejects non-activity bundles.
   *
   * @covers ::updateActivity
   */
  public function testUpdateActivityRejectsNonActivityBundle(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())
      ->method('bundle')
      ->willReturn('book');
    $node->expects($this->once())
      ->method('label')
      ->willReturn('Test Book');

    $this->messenger->expects($this->once())
      ->method('addError');

    // Node should NOT be saved.
    $node->expects($this->never())->method('save');

    $method = new \ReflectionMethod(ActivityController::class, 'updateActivity');
    $method->invoke($this->controller, $node, 'Finished');
  }

  /**
   * Tests updateActivity() updates valid activity.
   *
   * @covers ::updateActivity
   */
  public function testUpdateActivitySuccess(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('bundle')->willReturn('activity');
    $node->expects($this->once())->method('save');
    $node->expects($this->once())->method('label')->willReturn('Test Activity');

    // Mock getStatusByName query.
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([10]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);

    $this->messenger->expects($this->once())
      ->method('addStatus');

    $method = new \ReflectionMethod(ActivityController::class, 'updateActivity');
    $method->invoke($this->controller, $node, 'Finished');
  }

  /**
   * Tests getStatusByName() returns term ID.
   *
   * @covers ::getStatusByName
   */
  public function testGetStatusByName(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([42]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('getQuery')->willReturn($query);

    $this->entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->with('taxonomy_term')
      ->willReturn($storage);

    $method = new \ReflectionMethod(ActivityController::class, 'getStatusByName');
    $result = $method->invoke($this->controller, 'Reading');

    $this->assertEquals(42, $result);
  }

}

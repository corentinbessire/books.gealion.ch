<?php

namespace Drupal\Tests\books_activity\Unit\Controller;

use Drupal\books_activity\Controller\ActivityController;
use Drupal\books_activity\Services\ActivityStatusService;
use Drupal\books_catalog\Services\BookService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * @var \Drupal\books_catalog\Services\BookService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $bookService;

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
   * The mocked activity status service.
   *
   * @var \Drupal\books_activity\Services\ActivityStatusService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $activityStatus;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->bookService = $this->createMock(BookService::class);
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

    $this->activityStatus = $this->createMock(ActivityStatusService::class);

    $this->controller = new ActivityController(
      $this->entityTypeManager,
      $this->messenger,
      $this->bookService,
      $this->isbnToolsService,
      $requestStack,
      $this->activityStatus
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
   * Tests updateActivity() updates an activity that is being read.
   *
   * @covers ::updateActivity
   */
  public function testUpdateActivitySuccess(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('bundle')->willReturn('activity');
    $node->expects($this->once())->method('save');
    $node->method('label')->willReturn('Test Activity');

    $this->activityStatus->expects($this->once())
      ->method('isReading')
      ->with($node)
      ->willReturn(TRUE);
    $this->activityStatus->expects($this->once())
      ->method('getStatusId')
      ->with('Finished')
      ->willReturn(10);

    $this->messenger->expects($this->once())->method('addStatus');

    $method = new \ReflectionMethod(ActivityController::class, 'updateActivity');
    $method->invoke($this->controller, $node, 'Finished');
  }

  /**
   * Tests updateActivity() refuses an activity that is not being read.
   *
   * @covers ::updateActivity
   */
  public function testUpdateActivityRefusesActivityNotBeingRead(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('bundle')->willReturn('activity');
    $node->expects($this->never())->method('save');
    $node->method('label')->willReturn('Test Activity');

    $this->activityStatus->expects($this->once())
      ->method('isReading')
      ->with($node)
      ->willReturn(FALSE);
    $this->activityStatus->expects($this->never())->method('getStatusId');

    $this->messenger->expects($this->once())->method('addError');
    $this->messenger->expects($this->never())->method('addStatus');

    $method = new \ReflectionMethod(ActivityController::class, 'updateActivity');
    $method->invoke($this->controller, $node, 'Finished');
  }

}

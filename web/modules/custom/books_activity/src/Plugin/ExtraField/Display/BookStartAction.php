<?php

declare(strict_types=1);

namespace Drupal\books_activity\Plugin\ExtraField\Display;

use Drupal\books_activity\Services\ActivityStatusService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\extra_field\Attribute\ExtraFieldDisplay;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\extra_field_plus\Plugin\ExtraFieldPlusDisplayBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Offers to start, or restart, reading a book.
 *
 * The label follows the book's history: Start reading when it has never been
 * read, Reread when every activity is closed, and nothing at all while one is
 * open. ActivityController::new() enforces the same rule, because hiding a
 * button does not protect the route behind it.
 */
#[ExtraFieldDisplay(
  id: "book_start_action",
  label: new TranslatableMarkup("Start reading action"),
  description: new TranslatableMarkup("Start reading / Reread button, hidden while the book is already being read."),
  bundles: ["node.book"],
)]
class BookStartAction extends ExtraFieldPlusDisplayBase implements ContainerFactoryPluginInterface {

  /**
   * Permission required to open a reading activity.
   */
  protected const PERMISSION = 'create activity content';

  /**
   * Constructs a BookStartAction plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\books_activity\Services\ActivityStatusService $activityStatus
   *   Shared activity status rules.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ActivityStatusService $activityStatus,
    protected AccountProxyInterface $currentUser,
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
      $container->get('books_activity.status'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return array<string, mixed>
   */
  public function view(ContentEntityInterface $entity) {
    if (!$entity instanceof NodeInterface) {
      return [];
    }

    if (!$this->currentUser->hasPermission(self::PERMISSION)) {
      return [];
    }

    // The route is keyed by ISBN, so a book without one has nothing to link to.
    if (!$entity->hasField('field_isbn') || $entity->get('field_isbn')->isEmpty()) {
      return [];
    }

    $action = $this->activityStatus->getStartAction($entity);
    if ($action === NULL) {
      return [];
    }

    return [
      '#theme' => 'book_start_action',
      '#action' => $action,
      '#url' => Url::fromRoute('books_activity.new', [
        'isbn' => $entity->get('field_isbn')->value,
      ])->toString(),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $entity->getCacheTags(),
      ],
    ];
  }

}

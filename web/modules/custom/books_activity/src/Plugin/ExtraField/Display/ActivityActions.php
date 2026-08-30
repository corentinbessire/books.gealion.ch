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
 * Offers the reading-log transitions for an activity.
 *
 * Only shown for an activity still being read, and only to users who may edit
 * activities. ActivityController enforces the same rule, because hiding a
 * button does not protect the route behind it.
 */
#[ExtraFieldDisplay(
  id: "activity_actions",
  label: new TranslatableMarkup("Reading actions"),
  description: new TranslatableMarkup("Mark as read / Abandoned buttons for an activity still being read."),
  bundles: ["node.activity"],
)]
class ActivityActions extends ExtraFieldPlusDisplayBase implements ContainerFactoryPluginInterface {

  /**
   * Permission required to close an activity.
   */
  protected const PERMISSION = 'edit any activity content';

  /**
   * Constructs an ActivityActions plugin.
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

    if (!$this->activityStatus->isReading($entity)) {
      return [];
    }

    return [
      '#theme' => 'activity_actions',
      '#finish_url' => Url::fromRoute('books_activity.finish', ['activity' => $entity->id()])->toString(),
      '#abandon_url' => Url::fromRoute('books_activity.abandon', ['activity' => $entity->id()])->toString(),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $entity->getCacheTags(),
      ],
    ];
  }

}

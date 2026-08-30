<?php

declare(strict_types=1);

namespace Drupal\books_activity\Plugin\ExtraField\Display;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\extra_field\Attribute\ExtraFieldDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\extra_field_plus\Plugin\ExtraFieldPlusDisplayBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Example Extra field Display.
 */
#[ExtraFieldDisplay(
  id: "book_cover",
  label: new TranslatableMarkup("Book Cover"),
  description: new TranslatableMarkup("Display the cover of the book linked with the activity."),
  bundles: ["node.activity"],
)]
class BookCover extends ExtraFieldPlusDisplayBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ExtraFieldDisplayFormattedBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function view(ContentEntityInterface $entity) {
    $settings = $this->getEntityExtraFieldSettings();

    // The cover is reached through the activity's book, so the result depends
    // on entities the render pipeline never sees. The dependencies are
    // collected on every path, including the empty ones: without them a
    // cached "no cover" result would never notice the book gaining one.
    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($entity);

    $build = [];
    if ($book = $this->getFirstReference($entity, 'field_book')) {
      $cache->addCacheableDependency($book);

      if ($cover = $this->getFirstReference($book, 'field_cover')) {
        $cache->addCacheableDependency($cover);
        $build = $this->entityTypeManager->getViewBuilder('media')
          ->view($cover, $settings['image_style']);
      }
    }

    $cache->applyTo($build);

    return $build;
  }

  /**
   * Get the First entity of Entity Reference Field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The Parent Entity.
   * @param string $fieldName
   *   The machine name of the field to extract entity from.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The First entity referenced inf given field.
   */
  protected function getFirstReference(EntityInterface $entity, string $fieldName): ?EntityInterface {
    $referencedEntities = $entity->get($fieldName)->referencedEntities();
    if (empty($referencedEntities)) {
      return NULL;
    }
    return reset($referencedEntities);
  }

  /**
   * {@inheritdoc}
   */
  protected static function extraFieldSettingsForm(): array {
    $form = parent::extraFieldSettingsForm();

    $form['image_style'] = [
      '#type' => 'select',
      '#title' => t('Wrapper'),
      // t() rather than $this->t(): this method is static, so the
      // StringTranslationTrait is not available and a service cannot be
      // injected. Matches the t() call on the #title line above.
      '#options' => [
        'activity' => t('activity'),
        'reading' => t('reading'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected static function defaultExtraFieldSettings(): array {
    $values = parent::defaultExtraFieldSettings();

    $values += [
      'image_style' => 'activity',
    ];

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected static function settingsSummary(string $field_id, string $entity_type_id, string $bundle, string $view_mode = 'default'): array {
    return [
      t('Image Style: @image_style', [
        '@image_style' => self::getExtraFieldSetting($field_id, 'image_style', $entity_type_id, $bundle, $view_mode),
      ]),
    ];
  }

}

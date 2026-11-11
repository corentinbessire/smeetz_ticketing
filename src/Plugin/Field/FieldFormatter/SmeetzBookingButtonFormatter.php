<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dagda_smeetz\SmeetzUrlsInterface;
use Drupal\field\FieldConfigInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A button that opens the Smeetz booking widget for the item's product.
 *
 * The button is a link to the Smeetz booking page, so it still works without
 * JavaScript. With JavaScript, a click loads the Smeetz loader (never before)
 * and opens the widget over the page. If the loader fails or is blocked, the
 * link opens instead.
 */
#[FieldFormatter(
  id: 'dagda_smeetz_button',
  label: new TranslatableMarkup('Smeetz booking button', [], ['context' => 'Smeetz']),
  field_types: ['dagda_smeetz_ticket'],
)]
final class SmeetzBookingButtonFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * Field types whose first value can be passed to Smeetz as the show date.
   */
  private const DATE_FIELD_TYPES = [
    'datetime',
    'daterange',
    'smartdate',
    'timestamp',
    'created',
    'changed',
  ];

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    protected SmeetzUrlsInterface $urls,
    protected ConfigFactoryInterface $configFactory,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      (string) $configuration['label'],
      (string) $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get(SmeetzUrlsInterface::class),
      $container->get('config.factory'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'label' => 'Book a ticket',
      'date_field' => '',
      'lightbox' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button label', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('label'),
      '#required' => TRUE,
    ];
    $elements['date_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Date passed to Smeetz', [], ['context' => 'Smeetz']),
      '#options' => $this->dateFieldOptions(),
      '#empty_option' => $this->t('- None: Smeetz shows every date -', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('date_field'),
      '#description' => $this->t('Smeetz opens on the day of this field. It filters by day only: two sessions of the same product on the same day both appear.', [], ['context' => 'Smeetz']),
    ];
    $elements['lightbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open the widget as an overlay', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('lightbox'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary[] = $this->t('Label: @label', ['@label' => $this->getSetting('label')], ['context' => 'Smeetz']);
    $date_field = $this->getSetting('date_field');
    $summary[] = $date_field
      ? $this->t('Date: @field', ['@field' => $this->dateFieldOptions()[$date_field] ?? $date_field], ['context' => 'Smeetz'])
      : $this->t('No date passed', [], ['context' => 'Smeetz']);
    if (!$this->getSetting('lightbox')) {
      $summary[] = $this->t('Opens inline, not as an overlay', [], ['context' => 'Smeetz']);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $group_id = $this->urls->groupId();
    $show_date = $this->showDate($items->getEntity());

    foreach ($items as $delta => $item) {
      $product_id = (string) $item->get('product_id')->getValue();
      $attributes = [
        'href' => $this->urls->productPageUrl($product_id),
        'target' => '_blank',
        'rel' => 'noopener nofollow',
        'class' => ['dagda-smeetz-button'],
      ];
      $element = [
        '#theme' => 'dagda_smeetz_button',
        '#label' => $this->getSetting('label'),
        '#enhanced' => $group_id !== '',
        '#product_id' => $product_id,
        '#show_date' => $show_date,
      ];

      // Without a group ID the widget cannot open: keep the plain link.
      if ($group_id !== '') {
        $attributes += [
          'data-smeetz-product' => $product_id,
          'data-smeetz-lightbox' => $this->getSetting('lightbox') ? 'true' : 'false',
          // Smeetz takes a two-letter language: "fr", not "fr-ch".
          'data-smeetz-lang' => substr($langcode, 0, 2),
          'aria-haspopup' => 'dialog',
        ];
        if ($show_date !== NULL) {
          $attributes['data-smeetz-date'] = $show_date;
        }
        $element['#attached'] = [
          'library' => ['dagda_smeetz/booking'],
          'drupalSettings' => [
            'dagdaSmeetz' => [
              'groupId' => $group_id,
              'loaderUrl' => $this->urls->loaderUrl(),
            ],
          ],
        ];
      }
      $element['#attributes'] = $attributes;
      $elements[$delta] = $element;
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->configFactory->get('dagda_smeetz.settings'));
    $cacheability->addCacheableDependency($this->configFactory->get('system.date'));
    $cacheability->applyTo($elements);
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $field = $this->dateFieldDefinition();
    if ($field instanceof FieldConfigInterface) {
      $dependencies['config'][] = $field->getConfigDependencyName();
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = parent::onDependencyRemoval($dependencies);
    $field_name = (string) $this->getSetting('date_field');
    if ($field_name === '') {
      return $changed;
    }
    // Compare names: the field definition may already be gone by now.
    $config_name = implode('.', [
      'field.field',
      $this->fieldDefinition->getTargetEntityTypeId(),
      $this->fieldDefinition->getTargetBundle(),
      $field_name,
    ]);
    foreach ($dependencies['config'] ?? [] as $dependency) {
      $name = is_object($dependency) ? $dependency->getConfigDependencyName() : $dependency;
      if ($name === $config_name) {
        $this->setSetting('date_field', '');
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Returns the date of the entity as Smeetz expects it, in the site timezone.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the ticket field belongs to.
   *
   * @return string|null
   *   The day as YYYY-MM-DD, or NULL when no date field is set or it is empty.
   */
  private function showDate(FieldableEntityInterface $entity): ?string {
    $field_name = (string) $this->getSetting('date_field');
    if ($field_name === '' || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $entity->get($field_name)->first()?->getValue()['value'] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    // A date-only value is already a day, with no timezone to apply.
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value;
    }

    $timezone = new \DateTimeZone((string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC'));
    try {
      // Timestamps (Smart Date, timestamp) or a datetime stored in UTC.
      $date = is_numeric($value)
        ? (new \DateTimeImmutable('@' . (int) $value))
        : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }
    return $date->setTimezone($timezone)->format('Y-m-d');
  }

  /**
   * Lists the date fields of the bundle this formatter is used on.
   *
   * @return array<string, string>
   *   Field labels keyed by field name.
   */
  private function dateFieldOptions(): array {
    $definition = $this->fieldDefinition;
    $bundle = $definition->getTargetBundle();
    if ($bundle === NULL) {
      return [];
    }
    $options = [];
    $fields = $this->entityFieldManager->getFieldDefinitions($definition->getTargetEntityTypeId(), $bundle);
    foreach ($fields as $name => $field) {
      if (in_array($field->getType(), self::DATE_FIELD_TYPES, TRUE)) {
        $options[$name] = (string) $field->getLabel();
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Returns the definition of the configured date field, if it exists.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition, or NULL.
   */
  private function dateFieldDefinition(): ?FieldDefinitionInterface {
    $field_name = (string) $this->getSetting('date_field');
    $bundle = $this->fieldDefinition->getTargetBundle();
    if ($field_name === '' || $bundle === NULL) {
      return NULL;
    }
    $fields = $this->entityFieldManager->getFieldDefinitions($this->fieldDefinition->getTargetEntityTypeId(), $bundle);
    return $fields[$field_name] ?? NULL;
  }

}

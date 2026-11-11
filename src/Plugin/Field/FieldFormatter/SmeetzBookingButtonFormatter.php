<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Plugin\Field\FieldFormatter;

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
use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\smeetz_ticketing\FieldDate;
use Drupal\smeetz_ticketing\Plugin\Field\FieldType\SmeetzTicketItem;
use Drupal\smeetz_ticketing\SmeetzUrlsInterface;
use Drupal\smeetz_ticketing\Sync\AvailabilitySyncInterface;
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
  id: 'smeetz_ticketing_button',
  label: new TranslatableMarkup('Smeetz booking button', [], ['context' => 'Smeetz']),
  field_types: ['smeetz_ticketing_ticket'],
)]
final class SmeetzBookingButtonFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * Field types whose first value can be passed to Smeetz as the show date.
   */
  private const DATE_FIELD_TYPES = FieldDate::FIELD_TYPES;

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
    protected AvailabilitySyncInterface $sync,
    protected TimeInterface $time,
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
      $container->get(AvailabilitySyncInterface::class),
      $container->get(TimeInterface::class),
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
      'sold_out_label' => 'Sold out',
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
    $elements['sold_out_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label when sold out', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('sold_out_label'),
      '#required' => TRUE,
      '#description' => $this->t('Shown instead of the button when the availability sync reports the session as sold out.', [], ['context' => 'Smeetz']),
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
    $cacheability = new CacheableMetadata();
    $group_id = $this->urls->groupId();
    $show_date = $this->showDate($items->getEntity());

    $fresh = $this->sync->isFresh();
    $sold_out_shown = FALSE;

    foreach ($items as $delta => $item) {
      $product_id = (string) $item->get('product_id')->getValue();

      // Sold out, and the sync is recent enough to trust: no button.
      if ($fresh && $item->get('availability')->getValue() === SmeetzTicketItem::SOLD_OUT) {
        $sold_out_shown = TRUE;
        $elements[$delta] = [
          '#theme' => 'smeetz_ticketing_button',
          '#label' => $this->getSetting('sold_out_label'),
          '#sold_out' => TRUE,
          '#product_id' => $product_id,
          '#attributes' => [
            'class' => ['smeetz-ticketing-button', 'is-sold-out'],
            'aria-disabled' => 'true',
          ],
        ];
        continue;
      }

      $attributes = [
        'href' => $this->urls->productPageUrl($product_id),
        'target' => '_blank',
        'rel' => 'noopener nofollow',
        'class' => ['smeetz-ticketing-button'],
      ];
      $element = [
        '#theme' => 'smeetz_ticketing_button',
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
          'library' => ['smeetz_ticketing/booking'],
          'drupalSettings' => [
            'smeetzTicketing' => [
              'groupId' => $group_id,
              'loaderUrl' => $this->urls->loaderUrl(),
            ] + $this->consentSettings($cacheability),
          ],
        ];
      }
      $element['#attributes'] = $attributes;
      $elements[$delta] = $element;
    }

    $cacheability->addCacheableDependency($this->configFactory->get('smeetz_ticketing.settings'));
    $cacheability->addCacheableDependency($this->configFactory->get('system.date'));
    // The statuses stop counting a day after the last successful sync.
    $fresh_until = $this->sync->freshUntil();
    if ($fresh_until > 0) {
      $cacheability->mergeCacheMaxAge(max(0, $fresh_until - $this->time->getRequestTime()));
    }
    elseif ($sold_out_shown) {
      $cacheability->mergeCacheMaxAge(0);
    }
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
    return FieldDate::day($entity, (string) $this->getSetting('date_field'), $this->siteTimezone());
  }

  /**
   * Returns the site's default timezone.
   *
   * @return string
   *   A timezone name.
   */
  private function siteTimezone(): string {
    return (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
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
   * Returns the consent prompt settings for the JavaScript, if it is on.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cacheability of the privacy policy URL.
   *
   * @return array<string, array<string, string>>
   *   ['consent' => ['privacyUrl' => …]], or an empty array.
   */
  private function consentSettings(CacheableMetadata $cacheability): array {
    $config = $this->configFactory->get('smeetz_ticketing.settings');
    if (!$config->get('consent')) {
      return [];
    }
    $privacy = (string) $config->get('privacy_url');
    if (str_starts_with($privacy, '/')) {
      $generated = Url::fromUserInput($privacy)->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      $privacy = $generated->getGeneratedUrl();
    }
    return ['consent' => ['privacyUrl' => $privacy]];
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

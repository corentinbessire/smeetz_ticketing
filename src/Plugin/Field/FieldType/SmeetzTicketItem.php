<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\dagda_smeetz\FieldDate;

/**
 * A Smeetz ticket: the product sold, and its last known availability.
 *
 * Content that shares a product ID is sold on the same ticket. The
 * availability and check time are written by the availability sync, never by
 * editors.
 *
 * @property string $product_id
 * @property string|null $option_uuid
 * @property string|null $availability
 * @property int|null $checked
 */
#[FieldType(
  id: 'dagda_smeetz_ticket',
  label: new TranslatableMarkup('Smeetz ticket', [], ['context' => 'Smeetz']),
  description: new TranslatableMarkup('The Smeetz product a visitor books, opened in the Smeetz booking widget.', [], ['context' => 'Smeetz']),
  category: 'general',
  default_widget: 'dagda_smeetz_ticket',
  default_formatter: 'dagda_smeetz_button',
)]
final class SmeetzTicketItem extends FieldItemBase {

  /**
   * Availability value: seats can be booked.
   */
  public const AVAILABLE = 'available';

  /**
   * Availability value: sold out.
   */
  public const SOLD_OUT = 'sold_out';

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return [
      'date_field' => '',
      'status_field' => '',
      'status_available' => '',
      'status_sold_out' => '',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $definition = $this->getFieldDefinition();
    $date_fields = $list_fields = [];
    $bundle = $definition->getTargetBundle();
    if ($bundle !== NULL) {
      // Field items get no dependency injection.
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions($definition->getTargetEntityTypeId(), $bundle);
      foreach ($fields as $name => $field) {
        if (in_array($field->getType(), FieldDate::FIELD_TYPES, TRUE)) {
          $date_fields[$name] = (string) $field->getLabel();
        }
        if (in_array($field->getType(), ['list_string', 'list_integer'], TRUE)) {
          $list_fields[$name] = (string) $field->getLabel();
        }
      }
    }

    $element['date_field'] = [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Session date', [], ['context' => 'Smeetz']),
      '#options' => $date_fields,
      '#empty_option' => new TranslatableMarkup('- None -', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('date_field'),
      '#description' => new TranslatableMarkup('Used by the availability sync: past content is skipped, and each item is matched to the Smeetz slot that starts at this time. Without it, an item is sold out only when every slot of its option is.', [], ['context' => 'Smeetz']),
    ];
    $element['status_field'] = [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Also write the availability to', [], ['context' => 'Smeetz']),
      '#options' => $list_fields,
      '#empty_option' => new TranslatableMarkup('- Nothing -', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('status_field'),
      '#description' => new TranslatableMarkup('Optional: a list field the sync keeps in step, for a site that already shows its own status.', [], ['context' => 'Smeetz']),
    ];
    $element['status_available'] = [
      '#type' => 'textfield',
      '#title' => new TranslatableMarkup('Value when available', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('status_available'),
      '#size' => 20,
      '#states' => ['invisible' => [':input[name="settings[status_field]"]' => ['value' => '']]],
    ];
    $element['status_sold_out'] = [
      '#type' => 'textfield',
      '#title' => new TranslatableMarkup('Value when sold out', [], ['context' => 'Smeetz']),
      '#default_value' => $this->getSetting('status_sold_out'),
      '#size' => 20,
      '#states' => ['invisible' => [':input[name="settings[status_field]"]' => ['value' => '']]],
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['product_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Product ID', [], ['context' => 'Smeetz']))
      ->setRequired(TRUE);
    $properties['option_uuid'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Option UUID', [], ['context' => 'Smeetz']));
    $properties['availability'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Availability', [], ['context' => 'Smeetz']));
    $properties['checked'] = DataDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last availability check', [], ['context' => 'Smeetz']));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'product_id' => [
          'type' => 'varchar_ascii',
          'length' => 32,
          'not null' => TRUE,
          'default' => '',
        ],
        'option_uuid' => [
          'type' => 'varchar_ascii',
          'length' => 36,
        ],
        'availability' => [
          'type' => 'varchar_ascii',
          'length' => 16,
        ],
        'checked' => [
          'type' => 'int',
          'size' => 'big',
        ],
      ],
      'indexes' => [
        'product_id' => ['product_id'],
        'option_uuid' => ['option_uuid'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'product_id';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('product_id')->getValue();
    return $value === NULL || trim((string) $value) === '';
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();
    $manager = $this->getTypedDataManager()->getValidationConstraintManager();
    $constraints[] = $manager->create('ComplexData', [
      'product_id' => [
        'Regex' => [
          'pattern' => '/^[0-9]+$/',
          'message' => new TranslatableMarkup('The Smeetz product ID is a number.', [], ['context' => 'Smeetz']),
        ],
      ],
      'option_uuid' => [
        'Regex' => [
          'pattern' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
          'message' => new TranslatableMarkup('The Smeetz option UUID looks like 1b4e28ba-2fa1-11d2-883f-0016d3cca427.', [], ['context' => 'Smeetz']),
        ],
      ],
      'availability' => [
        'Choice' => [
          'choices' => [self::AVAILABLE, self::SOLD_OUT],
        ],
      ],
    ]);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    return ['product_id' => (string) random_int(10000, 99999)];
  }

}

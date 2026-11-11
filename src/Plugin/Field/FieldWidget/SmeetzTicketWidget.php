<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plain inputs for the Smeetz product ID and option UUID.
 *
 * The availability columns are kept as they are: only the sync writes them.
 */
#[FieldWidget(
  id: 'smeetz_ticketing_ticket',
  label: new TranslatableMarkup('Smeetz ticket', [], ['context' => 'Smeetz']),
  field_types: ['smeetz_ticketing_ticket'],
)]
final class SmeetzTicketWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    /** @var \Drupal\smeetz_ticketing\Plugin\Field\FieldType\SmeetzTicketItem $item */
    $item = $items[$delta];

    $element += [
      '#type' => 'details',
      '#open' => TRUE,
    ];
    $element['product_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product ID', [], ['context' => 'Smeetz']),
      '#default_value' => $item->product_id ?? '',
      '#size' => 12,
      '#maxlength' => 32,
      '#required' => $element['#required'],
      '#description' => $this->t('In the Smeetz back office, the number in the address of the product page. Content sold on the same ticket shares the same product ID.', [], ['context' => 'Smeetz']),
    ];
    $element['option_uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Option UUID', [], ['context' => 'Smeetz']),
      '#default_value' => $item->option_uuid ?? '',
      '#size' => 40,
      '#maxlength' => 36,
      '#description' => $this->t('Optional. Only needed to show "sold out" automatically: the ID of the ticket option, from the Smeetz back office.', [], ['context' => 'Smeetz']),
    ];
    $element['availability'] = [
      '#type' => 'value',
      '#value' => $item->availability ?? NULL,
    ];
    $element['checked'] = [
      '#type' => 'value',
      '#value' => $item->checked ?? NULL,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      $value['product_id'] = trim((string) ($value['product_id'] ?? ''));
      $uuid = trim((string) ($value['option_uuid'] ?? ''));
      $value['option_uuid'] = $uuid === '' ? NULL : strtolower($uuid);
    }
    return $values;
  }

}

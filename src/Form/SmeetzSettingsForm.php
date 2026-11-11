<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the Smeetz booking widget.
 *
 * The API key used by the availability sync is not set here: it is a secret,
 * read from $settings['dagda_smeetz']['api_key'] in settings.local.php.
 */
final class SmeetzSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dagda_smeetz_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dagda_smeetz.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Environment', [], ['context' => 'Smeetz']),
      '#options' => [
        'test' => $this->t('Test (test.tracker.smeetz.com)', [], ['context' => 'Smeetz']),
        'live' => $this->t('Live (tracker.smeetz.com)', [], ['context' => 'Smeetz']),
      ],
      '#description' => $this->t('Use Test while the site is being built. Bookings made on Test are not real.', [], ['context' => 'Smeetz']),
      '#config_target' => 'dagda_smeetz.settings:environment',
    ];
    $form['group_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group ID', [], ['context' => 'Smeetz']),
      '#description' => $this->t('The organiser ID in Smeetz, a number. It appears in the embed code of any Smeetz widget (gId). While it is empty, the booking buttons link to the Smeetz booking page instead of opening the widget.', [], ['context' => 'Smeetz']),
      '#size' => 12,
      '#maxlength' => 20,
      '#config_target' => 'dagda_smeetz.settings:group_id',
    ];
    return parent::buildForm($form, $form_state);
  }

}

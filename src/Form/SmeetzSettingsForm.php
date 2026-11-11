<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;

/**
 * Settings for the Smeetz booking widget.
 *
 * The API key used by the availability sync is not set here: it is a secret,
 * read from $settings['smeetz_ticketing']['api_key'] in settings.local.php.
 */
final class SmeetzSettingsForm extends ConfigFormBase {

  /**
   * Whether an API key is set in settings.php.
   *
   * @return bool
   *   TRUE when $settings['smeetz_ticketing']['api_key'] is not empty.
   */
  private function apiKeySet(): bool {
    $settings = Settings::get('smeetz_ticketing', []);
    return is_array($settings) && trim((string) ($settings['api_key'] ?? '')) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'smeetz_ticketing_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['smeetz_ticketing.settings'];
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
      '#config_target' => 'smeetz_ticketing.settings:environment',
    ];
    $form['group_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group ID', [], ['context' => 'Smeetz']),
      '#description' => $this->t('The organiser ID in Smeetz, a number. It appears in the embed code of any Smeetz widget (gId). While it is empty, the booking buttons link to the Smeetz booking page instead of opening the widget.', [], ['context' => 'Smeetz']),
      '#size' => 12,
      '#maxlength' => 20,
      '#config_target' => 'smeetz_ticketing.settings:group_id',
    ];
    $form['sync'] = [
      '#type' => 'details',
      '#title' => $this->t('Availability sync', [], ['context' => 'Smeetz']),
      '#open' => TRUE,
    ];
    $form['sync']['sync_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Check every (minutes)', [], ['context' => 'Smeetz']),
      '#min' => 0,
      '#step' => 1,
      '#field_suffix' => $this->t('minutes', [], ['context' => 'Smeetz']),
      '#description' => $this->t('How often cron asks Smeetz which sessions are sold out. 0 turns the sync off. One check covers every upcoming session in a few requests.', [], ['context' => 'Smeetz']),
      '#config_target' => 'smeetz_ticketing.settings:sync_interval',
    ];
    $form['sync']['api_key'] = [
      '#type' => 'item',
      '#title' => $this->t('API key', [], ['context' => 'Smeetz']),
      '#markup' => $this->apiKeySet()
        ? $this->t('Set in settings.php.', [], ['context' => 'Smeetz'])
        : $this->t("Not set. Add \$settings['smeetz_ticketing']['api_key'] to settings.local.php: it is a secret and never goes in configuration.", [], ['context' => 'Smeetz']),
    ];
    return parent::buildForm($form, $form_state);
  }

}

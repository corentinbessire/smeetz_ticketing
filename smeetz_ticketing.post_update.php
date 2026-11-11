<?php

/**
 * @file
 * Post update functions for Smeetz Ticketing.
 */

declare(strict_types=1);

/**
 * Adds the consent settings, off by default.
 */
function smeetz_ticketing_post_update_consent_settings(): void {
  $config = \Drupal::configFactory()->getEditable('smeetz_ticketing.settings');
  foreach (['consent' => FALSE, 'privacy_url' => ''] as $key => $default) {
    if ($config->get($key) === NULL) {
      $config->set($key, $default);
    }
  }
  $config->save(TRUE);
}

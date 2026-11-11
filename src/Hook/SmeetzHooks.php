<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Dagda Smeetz.
 */
final class SmeetzHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'dagda_smeetz_button' => [
        'variables' => [
          'label' => '',
          'attributes' => [],
          'enhanced' => FALSE,
          'product_id' => '',
          'show_date' => NULL,
        ],
      ],
    ];
  }

}

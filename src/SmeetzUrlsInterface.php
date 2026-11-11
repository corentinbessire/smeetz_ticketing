<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz;

use Drupal\dagda_smeetz\Enum\SmeetzEnvironment;

/**
 * Reads the Smeetz settings and builds the URLs the booking button needs.
 */
interface SmeetzUrlsInterface {

  /**
   * Returns the configured Smeetz environment.
   *
   * @return \Drupal\dagda_smeetz\Enum\SmeetzEnvironment
   *   The environment, test when the setting is missing or unknown.
   */
  public function environment(): SmeetzEnvironment;

  /**
   * Returns the Smeetz group ID of the organiser.
   *
   * @return string
   *   The group ID, or an empty string when it is not set.
   */
  public function groupId(): string;

  /**
   * Returns the URL of the Smeetz widget loader for the environment.
   *
   * @return string
   *   The absolute URL of the loader script.
   */
  public function loaderUrl(): string;

  /**
   * Returns the Smeetz booking page of a product.
   *
   * This page works without the widget, so it is the link the button falls
   * back to when JavaScript is off or the loader is blocked.
   *
   * @param string $product_id
   *   The Smeetz product ID.
   *
   * @return string
   *   The absolute URL of the product's booking page.
   */
  public function productPageUrl(string $product_id): string;

}

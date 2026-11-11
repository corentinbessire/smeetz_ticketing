<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Enum;

/**
 * The Smeetz environment a site books against.
 */
enum SmeetzEnvironment: string {

  case Test = 'test';
  case Live = 'live';

  /**
   * Returns the URL of the Smeetz widget loader script.
   *
   * @return string
   *   The absolute URL of smeetz-main-widget.js.
   */
  public function loaderUrl(): string {
    return match ($this) {
      self::Test => 'https://test.tracker.smeetz.com/smeetz-main-widget.js',
      self::Live => 'https://tracker.smeetz.com/smeetz-main-widget.js',
    };
  }

  /**
   * Returns the base URL of the Smeetz booking pages.
   *
   * @return string
   *   The scheme and host, without a trailing slash.
   */
  public function widgetBaseUrl(): string {
    return match ($this) {
      self::Test => 'https://test-widget.smeetz.com',
      self::Live => 'https://widget.smeetz.com',
    };
  }

  /**
   * Returns the base URL of the Smeetz public API.
   *
   * @return string
   *   The scheme and host, without a trailing slash.
   */
  public function apiBaseUrl(): string {
    return match ($this) {
      self::Test => 'https://services.test.smeetz.com',
      self::Live => 'https://services-live.smeetz.com',
    };
  }

}

<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Api;

/**
 * Talks to the Smeetz public API.
 */
interface SmeetzApiClientInterface {

  /**
   * Option UUIDs sent per request, to keep the URL short.
   */
  public const CHUNK_SIZE = 50;

  /**
   * Slots per page: the maximum Smeetz returns.
   */
  public const PAGE_SIZE = 100;

  /**
   * Whether an API key is set in settings.php.
   *
   * @return bool
   *   TRUE when $settings['smeetz_ticketing']['api_key'] is not empty.
   */
  public function hasApiKey(): bool;

  /**
   * Fetches the availability slots of options between two moments.
   *
   * All the options go in as few requests as possible: chunks of
   * CHUNK_SIZE UUIDs, each read page by page.
   *
   * @param string[] $option_ids
   *   Product option UUIDs.
   * @param int $from
   *   Start of the range, as a Unix timestamp.
   * @param int $to
   *   End of the range, as a Unix timestamp.
   *
   * @return \Drupal\smeetz_ticketing\Api\AvailabilitySlot[]
   *   The slots.
   *
   * @throws \Drupal\smeetz_ticketing\Exception\SmeetzRateLimitException
   *   When Smeetz answers HTTP 429.
   * @throws \Drupal\smeetz_ticketing\Exception\SmeetzApiException
   *   When the API key is missing, Smeetz is unreachable or answers an error.
   */
  public function availabilities(array $option_ids, int $from, int $to): array;

  /**
   * Returns the number of HTTP requests sent since the service was created.
   *
   * @return int
   *   The request count.
   */
  public function requestCount(): int;

}

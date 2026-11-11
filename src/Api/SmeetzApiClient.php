<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Api;

use Drupal\Core\Site\Settings;
use Drupal\smeetz_ticketing\Exception\SmeetzApiException;
use Drupal\smeetz_ticketing\Exception\SmeetzRateLimitException;
use Drupal\smeetz_ticketing\SmeetzUrlsInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Smeetz public API client (Get Availabilities).
 *
 * @see https://developer-live.smeetz.com/docs/core-apis/rest-api/availability-public-controller-get-availabilities.md
 */
final class SmeetzApiClient implements SmeetzApiClientInterface {

  /**
   * Seconds to wait for Smeetz before giving up.
   */
  private const TIMEOUT = 5;

  /**
   * Pages read per chunk at most, so a bad answer cannot loop forever.
   */
  private const MAX_PAGES = 50;

  /**
   * HTTP requests sent so far.
   */
  private int $requests = 0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Settings $settings,
    private readonly SmeetzUrlsInterface $urls,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hasApiKey(): bool {
    return $this->apiKey() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function availabilities(array $option_ids, int $from, int $to): array {
    if (!$this->hasApiKey()) {
      throw new SmeetzApiException('No Smeetz API key in $settings[\'smeetz_ticketing\'][\'api_key\'].');
    }
    $option_ids = array_values(array_unique(array_filter($option_ids)));
    $slots = [];
    foreach (array_chunk($option_ids, self::CHUNK_SIZE) as $chunk) {
      for ($page = 1; $page <= self::MAX_PAGES; $page++) {
        $data = $this->request($chunk, $from, $to, $page);
        foreach ($data as $row) {
          $slot = $this->slot($row);
          if ($slot) {
            $slots[] = $slot;
          }
        }
        if (count($data) < self::PAGE_SIZE) {
          break;
        }
      }
    }
    return $slots;
  }

  /**
   * {@inheritdoc}
   */
  public function requestCount(): int {
    return $this->requests;
  }

  /**
   * Sends one Get Availabilities request.
   *
   * @param string[] $option_ids
   *   Option UUIDs of this chunk.
   * @param int $from
   *   Start of the range, as a Unix timestamp.
   * @param int $to
   *   End of the range, as a Unix timestamp.
   * @param int $page
   *   The page, from 1.
   *
   * @return array<int, mixed>
   *   The "data" rows.
   */
  private function request(array $option_ids, int $from, int $to, int $page): array {
    $this->requests++;
    try {
      $response = $this->httpClient->request('GET', $this->urls->environment()->apiBaseUrl() . '/public/v1/availability', [
        'query' => [
          'option_ids' => implode(',', $option_ids),
          'date_time_start' => gmdate('Y-m-d\TH:i:s\Z', $from),
          'date_time_end' => gmdate('Y-m-d\TH:i:s\Z', $to),
          'locale' => 'en',
          'page' => $page,
          'page_size' => self::PAGE_SIZE,
        ],
        'headers' => [
          'Accept' => 'application/json',
          'x-public-api-key' => $this->apiKey(),
        ],
        'timeout' => self::TIMEOUT,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new SmeetzApiException('Smeetz is unreachable: ' . $e->getMessage(), 0, $e);
    }

    $status = $response->getStatusCode();
    if ($status === 429) {
      throw new SmeetzRateLimitException('Smeetz rate limit reached (HTTP 429).');
    }
    if ($status !== 200) {
      throw new SmeetzApiException(sprintf('Smeetz answered HTTP %d.', $status));
    }
    try {
      $body = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new SmeetzApiException('Smeetz answered invalid JSON.', 0, $e);
    }
    if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
      throw new SmeetzApiException('Smeetz answered without a "data" list.');
    }
    return array_values($body['data']);
  }

  /**
   * Turns a response row into a slot.
   *
   * @param mixed $row
   *   One "data" row.
   *
   * @return \Drupal\smeetz_ticketing\Api\AvailabilitySlot|null
   *   The slot, or NULL when the row lacks what the sync needs.
   */
  private function slot(mixed $row): ?AvailabilitySlot {
    if (!is_array($row) || !isset($row['optionId'], $row['dateTimeStart'], $row['available'])) {
      return NULL;
    }
    try {
      // dateTimeStart is UTC, written without a zone.
      $start = new \DateTimeImmutable((string) $row['dateTimeStart'], new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }
    return new AvailabilitySlot(strtolower((string) $row['optionId']), $start->getTimestamp(), (bool) $row['available']);
  }

  /**
   * Returns the API key from settings.php.
   *
   * @return string
   *   The key, or an empty string.
   */
  private function apiKey(): string {
    $settings = $this->settings->get('smeetz_ticketing', []);
    return is_array($settings) ? trim((string) ($settings['api_key'] ?? '')) : '';
  }

}

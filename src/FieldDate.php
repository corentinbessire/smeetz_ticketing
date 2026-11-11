<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Reads the first value of a date field as a moment in time.
 *
 * Works for datetime and daterange (stored in UTC, or a bare day), Smart
 * Date and timestamp fields.
 */
final class FieldDate {

  /**
   * Field types this helper reads.
   */
  public const FIELD_TYPES = [
    'datetime',
    'daterange',
    'smartdate',
    'timestamp',
    'created',
    'changed',
  ];

  /**
   * Returns the raw first value of a date field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The date field.
   *
   * @return int|string|null
   *   A timestamp, a "Y-m-d" day, a UTC "Y-m-d\TH:i:s" string, or NULL.
   */
  public static function raw(FieldableEntityInterface $entity, string $field_name): int|string|null {
    if ($field_name === '' || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $entity->get($field_name)->first()?->getValue()['value'] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return is_numeric($value) ? (int) $value : (string) $value;
  }

  /**
   * Returns the start of a date field as a Unix timestamp.
   *
   * A bare day ("Y-m-d") counts as midnight in the given timezone.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The date field.
   * @param string $timezone
   *   The timezone of a bare day, usually the site's.
   *
   * @return int|null
   *   The timestamp, or NULL when the field is empty or unreadable.
   */
  public static function timestamp(FieldableEntityInterface $entity, string $field_name, string $timezone = 'UTC'): ?int {
    $value = self::raw($entity, $field_name);
    if ($value === NULL) {
      return NULL;
    }
    if (is_int($value)) {
      return $value;
    }
    try {
      $zone = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $timezone : 'UTC';
      return (new \DateTimeImmutable($value, new \DateTimeZone($zone)))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Returns the day of a date field in a timezone.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The date field.
   * @param string $timezone
   *   The timezone to read the day in, usually the site's.
   *
   * @return string|null
   *   The day as "Y-m-d", or NULL.
   */
  public static function day(FieldableEntityInterface $entity, string $field_name, string $timezone): ?string {
    $value = self::raw($entity, $field_name);
    // A bare day has no timezone to apply.
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value;
    }
    $timestamp = self::timestamp($entity, $field_name);
    if ($timestamp === NULL) {
      return NULL;
    }
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
  }

}

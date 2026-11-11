<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Api;

/**
 * One availability slot of a Smeetz product option.
 */
final class AvailabilitySlot {

  public function __construct(
    public readonly string $optionId,
    public readonly int $start,
    public readonly bool $available,
  ) {}

}

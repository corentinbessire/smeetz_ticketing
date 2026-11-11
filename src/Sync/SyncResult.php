<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Sync;

/**
 * The outcome of one availability sync.
 */
final class SyncResult {

  public function __construct(
    public readonly bool $success,
    public readonly int $items = 0,
    public readonly int $changed = 0,
    public readonly int $requests = 0,
    public readonly string $message = '',
  ) {}

}

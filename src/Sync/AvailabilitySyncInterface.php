<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Sync;

/**
 * Reads ticket availability from Smeetz and writes it to the ticket fields.
 */
interface AvailabilitySyncInterface {

  /**
   * State key: Unix time of the last successful sync.
   */
  public const STATE_LAST_SUCCESS = 'smeetz_ticketing.sync.last_success';

  /**
   * State key: Unix time of the last attempt, successful or not.
   */
  public const STATE_LAST_ATTEMPT = 'smeetz_ticketing.sync.last_attempt';

  /**
   * State key: requests made by the last successful sync.
   */
  public const STATE_LAST_REQUESTS = 'smeetz_ticketing.sync.last_requests';

  /**
   * Seconds after which a status no longer counts if no sync succeeded.
   */
  public const STALE_AFTER = 86400;

  /**
   * Runs one sync of every upcoming item with an option UUID.
   *
   * Never throws: errors are logged, the last known statuses stay.
   *
   * @return \Drupal\smeetz_ticketing\Sync\SyncResult
   *   What the run did.
   */
  public function run(): SyncResult;

  /**
   * Whether the stored statuses are recent enough to show.
   *
   * @return bool
   *   TRUE when a sync succeeded within STALE_AFTER seconds.
   */
  public function isFresh(): bool;

  /**
   * Returns when the stored statuses stop counting.
   *
   * @return int
   *   A Unix time: the last success plus STALE_AFTER, or 0 if none.
   */
  public function freshUntil(): int;

}

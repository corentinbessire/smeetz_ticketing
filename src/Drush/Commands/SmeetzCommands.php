<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Drush\Commands;

use Drupal\smeetz_ticketing\Sync\AvailabilitySyncInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Smeetz Ticketing.
 */
final class SmeetzCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly AvailabilitySyncInterface $sync,
  ) {
    parent::__construct();
  }

  /**
   * Checks ticket availability on Smeetz now, whatever the interval.
   */
  #[CLI\Command(name: 'smeetz:sync')]
  #[CLI\Usage(name: 'drush smeetz:sync', description: 'Run the Smeetz availability sync.')]
  public function sync(): int {
    $result = $this->sync->run();
    if (!$result->success) {
      $this->logger()->error($result->message);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('@items items checked, @changed changed, @requests requests. @message', [
      '@items' => $result->items,
      '@changed' => $result->changed,
      '@requests' => $result->requests,
      '@message' => $result->message,
    ]));
    return self::EXIT_SUCCESS;
  }

}

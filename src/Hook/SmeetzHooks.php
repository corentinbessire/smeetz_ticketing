<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\smeetz_ticketing\Api\SmeetzApiClientInterface;
use Drupal\smeetz_ticketing\Sync\AvailabilitySyncInterface;

/**
 * Hook implementations for Smeetz Ticketing.
 */
final class SmeetzHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly AvailabilitySyncInterface $sync,
    private readonly SmeetzApiClientInterface $client,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'smeetz_ticketing_button' => [
        'variables' => [
          'label' => '',
          'attributes' => [],
          'enhanced' => FALSE,
          'sold_out' => FALSE,
          'product_id' => '',
          'show_date' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   *
   * Runs the availability sync once the interval has passed. One bulk check
   * is a handful of requests at most, so it runs here rather than in a queue.
   */
  #[Hook('cron')]
  public function cron(): void {
    $interval = (int) $this->configFactory->get('smeetz_ticketing.settings')->get('sync_interval');
    if ($interval <= 0 || !$this->client->hasApiKey()) {
      return;
    }
    $last = (int) $this->state->get(AvailabilitySyncInterface::STATE_LAST_ATTEMPT, 0);
    if ($this->time->getRequestTime() - $last < $interval * 60) {
      return;
    }
    $this->sync->run();
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $interval = (int) $this->configFactory->get('smeetz_ticketing.settings')->get('sync_interval');
    if ($interval <= 0) {
      return [];
    }
    $requirement = ['title' => $this->t('Smeetz availability sync', [], ['context' => 'Smeetz'])];
    if (!$this->client->hasApiKey()) {
      $requirement['value'] = $this->t('No API key', [], ['context' => 'Smeetz']);
      $requirement['description'] = $this->t("Set \$settings['smeetz_ticketing']['api_key'] in settings.local.php to show sold-out sessions automatically.", [], ['context' => 'Smeetz']);
      $requirement['severity'] = RequirementSeverity::Warning;
      return ['smeetz_ticketing_sync' => $requirement];
    }

    $last = (int) $this->state->get(AvailabilitySyncInterface::STATE_LAST_SUCCESS, 0);
    if ($last === 0) {
      $requirement['value'] = $this->t('Never run', [], ['context' => 'Smeetz']);
      $requirement['severity'] = RequirementSeverity::Warning;
    }
    else {
      $requirement['value'] = $this->t('Last success @ago ago, @count requests', [
        '@ago' => $this->dateFormatter->formatTimeDiffSince($last),
        '@count' => (int) $this->state->get(AvailabilitySyncInterface::STATE_LAST_REQUESTS, 0),
      ], ['context' => 'Smeetz']);
      $requirement['severity'] = $this->sync->isFresh() ? RequirementSeverity::OK : RequirementSeverity::Warning;
      if (!$this->sync->isFresh()) {
        $requirement['description'] = $this->t('No successful sync for a day: sold-out badges are hidden until Smeetz answers again. See the log for errors.', [], ['context' => 'Smeetz']);
      }
    }
    return ['smeetz_ticketing_sync' => $requirement];
  }

}

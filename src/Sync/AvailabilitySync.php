<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Sync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\smeetz_ticketing\Api\AvailabilitySlot;
use Drupal\smeetz_ticketing\Api\SmeetzApiClientInterface;
use Drupal\smeetz_ticketing\Exception\SmeetzApiException;
use Drupal\smeetz_ticketing\FieldDate;
use Drupal\smeetz_ticketing\Plugin\Field\FieldType\SmeetzTicketItem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One bulk availability check for every upcoming Smeetz ticket.
 *
 * Collects the option UUIDs of all upcoming items, asks Smeetz once per chunk
 * of UUIDs for the whole date range, then matches each slot to its item by
 * option and start time. An item is saved only when its status changes.
 */
final class AvailabilitySync implements AvailabilitySyncInterface {

  /**
   * How far ahead to look for items without a date, in seconds.
   */
  private const UNDATED_HORIZON = 365 * 86400;

  /**
   * Slots starting this many seconds apart from the item still match.
   */
  private const START_TOLERANCE = 60;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly SmeetzApiClientInterface $client,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'logger.channel.smeetz_ticketing')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(): SyncResult {
    if (!$this->client->hasApiKey()) {
      return new SyncResult(FALSE, message: 'No Smeetz API key in settings.php.');
    }
    if (!$this->lock->acquire('smeetz_ticketing_sync', 120)) {
      return new SyncResult(FALSE, message: 'Another sync is running.');
    }
    $now = $this->time->getRequestTime();
    $this->state->set(self::STATE_LAST_ATTEMPT, $now);
    $requests_before = $this->client->requestCount();

    try {
      $targets = $this->targets($now);
      if ($targets === []) {
        // Outside the sales period: no call at all.
        $this->state->set(self::STATE_LAST_SUCCESS, $now);
        $this->state->set(self::STATE_LAST_REQUESTS, 0);
        return new SyncResult(TRUE, message: 'No upcoming item to check.');
      }

      $option_ids = array_values(array_unique(array_column($targets, 'option')));
      $starts = array_filter(array_column($targets, 'start'));
      $to = count($starts) === count($targets)
        ? max($starts) + 86400
        : $now + self::UNDATED_HORIZON;
      $slots = $this->client->availabilities($option_ids, $now - 86400, $to);
      $requests = $this->client->requestCount() - $requests_before;

      $changed = $this->apply($targets, $this->index($slots), $now);
      $this->state->set(self::STATE_LAST_SUCCESS, $now);
      $this->state->set(self::STATE_LAST_REQUESTS, $requests);
      $this->logger->info('Smeetz availability sync: @items items checked, @changed changed, @requests requests.', [
        '@items' => count($targets),
        '@changed' => $changed,
        '@requests' => $requests,
      ]);
      return new SyncResult(TRUE, count($targets), $changed, $requests);
    }
    catch (SmeetzApiException $e) {
      // Keep the last known statuses; the next cron run tries again.
      $this->logger->error('Smeetz availability sync failed: @message', ['@message' => $e->getMessage()]);
      return new SyncResult(FALSE, requests: $this->client->requestCount() - $requests_before, message: $e->getMessage());
    }
    finally {
      $this->lock->release('smeetz_ticketing_sync');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isFresh(): bool {
    return $this->freshUntil() > $this->time->getRequestTime();
  }

  /**
   * {@inheritdoc}
   */
  public function freshUntil(): int {
    $last = (int) $this->state->get(self::STATE_LAST_SUCCESS, 0);
    return $last > 0 ? $last + self::STALE_AFTER : 0;
  }

  /**
   * Lists the ticket items to check: upcoming, published, with an option.
   *
   * @param int $now
   *   The current time.
   *
   * @return array<int, array{entity_type: string, id: int|string, field: string, delta: int, option: string, start: int|null}>
   *   One entry per field item.
   */
  private function targets(int $now): array {
    $timezone = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
    $targets = [];
    $map = $this->entityFieldManager->getFieldMapByFieldType('smeetz_ticketing_ticket');
    foreach ($map as $entity_type_id => $fields) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach ($fields as $field_name => $info) {
        foreach ($info['bundles'] as $bundle) {
          $definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name] ?? NULL;
          if (!$definition instanceof FieldDefinitionInterface) {
            continue;
          }
          $date_field = (string) $definition->getSetting('date_field');
          $query = $storage->getQuery()
            ->accessCheck(FALSE)
            ->exists($field_name . '.option_uuid');
          $bundle_key = $storage->getEntityType()->getKey('bundle');
          if ($bundle_key) {
            $query->condition($bundle_key, $bundle);
          }
          $published_key = $storage->getEntityType()->getKey('published');
          if ($published_key) {
            $query->condition($published_key, 1);
          }
          $ids = $query->execute();
          foreach ($storage->loadMultiple($ids) as $entity) {
            if (!$entity instanceof ContentEntityInterface) {
              continue;
            }
            $start = $date_field !== '' ? FieldDate::timestamp($entity, $date_field, $timezone) : NULL;
            // Past sessions are skipped; with a date field, so are undated
            // items, which cannot be matched to a slot.
            if ($date_field !== '' && ($start === NULL || $start < $now)) {
              continue;
            }
            foreach ($entity->get($field_name) as $delta => $item) {
              $option = strtolower(trim((string) $item->get('option_uuid')->getValue()));
              if ($option !== '') {
                $targets[] = [
                  'entity_type' => $entity_type_id,
                  'id' => $entity->id(),
                  'field' => $field_name,
                  'delta' => (int) $delta,
                  'option' => $option,
                  'start' => $start,
                ];
              }
            }
          }
        }
      }
    }
    return $targets;
  }

  /**
   * Groups slots by option UUID.
   *
   * @param \Drupal\smeetz_ticketing\Api\AvailabilitySlot[] $slots
   *   The slots.
   *
   * @return array<string, \Drupal\smeetz_ticketing\Api\AvailabilitySlot[]>
   *   Slots keyed by option UUID.
   */
  private function index(array $slots): array {
    $index = [];
    foreach ($slots as $slot) {
      $index[$slot->optionId][] = $slot;
    }
    return $index;
  }

  /**
   * Works out each item's availability and saves the items that changed.
   *
   * @param array<int, array{entity_type: string, id: int|string, field: string, delta: int, option: string, start: int|null}> $targets
   *   The items checked.
   * @param array<string, \Drupal\smeetz_ticketing\Api\AvailabilitySlot[]> $slots
   *   Slots keyed by option UUID.
   * @param int $now
   *   The current time.
   *
   * @return int
   *   The number of items whose status changed.
   */
  private function apply(array $targets, array $slots, int $now): int {
    $changes = [];
    foreach ($targets as $target) {
      $availability = $this->availability($slots[$target['option']] ?? [], $target['start']);
      if ($availability !== NULL) {
        $changes[$target['entity_type']][$target['id']][$target['field']][$target['delta']] = $availability;
      }
    }

    $changed = 0;
    foreach ($changes as $entity_type_id => $entities) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach ($storage->loadMultiple(array_keys($entities)) as $id => $entity) {
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $dirty = FALSE;
        foreach ($entities[$id] as $field_name => $deltas) {
          foreach ($deltas as $delta => $availability) {
            $item = $entity->get($field_name)->get($delta);
            if (!$item || $item->get('availability')->getValue() === $availability) {
              continue;
            }
            $item->set('availability', $availability);
            $item->set('checked', $now);
            $this->writeStatusField($entity, $field_name, $availability);
            $dirty = TRUE;
            $changed++;
          }
        }
        if ($dirty) {
          $this->saveQuietly($entity);
        }
      }
    }
    return $changed;
  }

  /**
   * Works out an item's availability from its option's slots.
   *
   * @param \Drupal\smeetz_ticketing\Api\AvailabilitySlot[] $slots
   *   The slots of the item's option.
   * @param int|null $start
   *   The item's start, or NULL when it has no date.
   *
   * @return string|null
   *   SmeetzTicketItem::AVAILABLE or ::SOLD_OUT, or NULL when no slot
   *   matches (the status is left as it is).
   */
  private function availability(array $slots, ?int $start): ?string {
    if ($start !== NULL) {
      $slots = array_filter($slots, static fn (AvailabilitySlot $slot): bool => abs($slot->start - $start) <= self::START_TOLERANCE);
    }
    if ($slots === []) {
      return NULL;
    }
    foreach ($slots as $slot) {
      if ($slot->available) {
        return SmeetzTicketItem::AVAILABLE;
      }
    }
    return SmeetzTicketItem::SOLD_OUT;
  }

  /**
   * Copies the availability into the list field named in the field settings.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The ticket field.
   * @param string $availability
   *   The new availability.
   */
  private function writeStatusField(ContentEntityInterface $entity, string $field_name, string $availability): void {
    $definition = $entity->getFieldDefinition($field_name);
    $status_field = (string) $definition?->getSetting('status_field');
    if ($status_field === '' || !$entity->hasField($status_field)) {
      return;
    }
    $value = (string) $definition->getSetting($availability === SmeetzTicketItem::SOLD_OUT ? 'status_sold_out' : 'status_available');
    if ($value !== '') {
      $entity->set($status_field, $value);
    }
  }

  /**
   * Saves an entity without a new revision or a "changed" time update.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   */
  private function saveQuietly(ContentEntityInterface $entity): void {
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(FALSE);
    }
    $entity->setSyncing(TRUE);
    $entity->save();
  }

}

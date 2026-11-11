<?php

declare(strict_types=1);

namespace Drupal\Tests\dagda_smeetz\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;

/**
 * Sets up an entity_test bundle with a Smeetz ticket field and a date.
 */
abstract class SmeetzKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dagda_smeetz',
    'datetime',
    'entity_test',
    'field',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'field', 'dagda_smeetz']);

    FieldStorageConfig::create([
      'field_name' => 'field_ticket',
      'entity_type' => 'entity_test',
      'type' => 'dagda_smeetz_ticket',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ticket',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Ticket',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'entity_test',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'datetime'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'When',
    ])->save();
  }

  /**
   * Creates and saves a test entity.
   *
   * @param array<string, mixed> $values
   *   Field values.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The saved entity.
   */
  protected function createEntity(array $values): EntityTest {
    $entity = EntityTest::create($values + ['name' => $this->randomMachineName()]);
    $entity->save();
    return $entity;
  }

}

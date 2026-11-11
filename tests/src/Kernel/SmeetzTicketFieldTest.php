<?php

declare(strict_types=1);

namespace Drupal\Tests\smeetz_ticketing\Kernel;

use Drupal\smeetz_ticketing\Plugin\Field\FieldType\SmeetzTicketItem;
use Drupal\entity_test\Entity\EntityTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Smeetz ticket field type.
 */
#[CoversClass(SmeetzTicketItem::class)]
#[Group('smeetz_ticketing')]
#[RunTestsInSeparateProcesses]
final class SmeetzTicketFieldTest extends SmeetzKernelTestBase {

  /**
   * Tests that values are stored and read back.
   */
  public function testStorage(): void {
    $entity = $this->createEntity([
      'field_ticket' => [
        'product_id' => '39161',
        'option_uuid' => '1b4e28ba-2fa1-11d2-883f-0016d3cca427',
        'availability' => SmeetzTicketItem::SOLD_OUT,
        'checked' => 1794409729,
      ],
    ]);
    $loaded = EntityTest::load($entity->id());
    $item = $loaded->get('field_ticket')->first();
    $this->assertSame('39161', $item->get('product_id')->getValue());
    $this->assertSame('1b4e28ba-2fa1-11d2-883f-0016d3cca427', $item->get('option_uuid')->getValue());
    $this->assertSame(SmeetzTicketItem::SOLD_OUT, $item->get('availability')->getValue());
    $this->assertEquals(1794409729, $item->get('checked')->getValue());
    // The product ID is the main property.
    $this->assertSame('product_id', SmeetzTicketItem::mainPropertyName());
  }

  /**
   * Tests that an item without a product ID counts as empty.
   */
  public function testEmpty(): void {
    $entity = $this->createEntity(['field_ticket' => ['product_id' => '  ']]);
    $this->assertTrue($entity->get('field_ticket')->isEmpty());
  }

  /**
   * Tests the validation of the product ID, UUID and availability.
   */
  public function testValidation(): void {
    $entity = EntityTest::create([
      'field_ticket' => [
        'product_id' => '39161',
        'option_uuid' => '1b4e28ba-2fa1-11d2-883f-0016d3cca427',
        'availability' => SmeetzTicketItem::AVAILABLE,
      ],
    ]);
    $this->assertCount(0, $entity->get('field_ticket')->validate());

    $entity->set('field_ticket', [
      'product_id' => 'abc',
      'option_uuid' => 'not-a-uuid',
      'availability' => 'maybe',
    ]);
    $paths = [];
    foreach ($entity->get('field_ticket')->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    sort($paths);
    $this->assertSame(['0.availability', '0.option_uuid', '0.product_id'], $paths);
  }

}

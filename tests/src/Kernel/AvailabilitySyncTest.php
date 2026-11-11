<?php

declare(strict_types=1);

namespace Drupal\Tests\dagda_smeetz\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\dagda_smeetz\Plugin\Field\FieldType\SmeetzTicketItem;
use Drupal\dagda_smeetz\Sync\AvailabilitySync;
use Drupal\dagda_smeetz\Sync\AvailabilitySyncInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the availability sync against a mocked Smeetz API.
 */
#[CoversClass(AvailabilitySync::class)]
#[Group('dagda_smeetz')]
#[RunTestsInSeparateProcesses]
final class AvailabilitySyncTest extends SmeetzKernelTestBase {

  private const OPTION_A = 'aaaaaaaa-0000-4000-8000-000000000001';
  private const OPTION_B = 'bbbbbbbb-0000-4000-8000-000000000002';
  private const OPTION_C = 'cccccccc-0000-4000-8000-000000000003';

  /**
   * Responses the mocked Smeetz API returns, in order.
   */
  private MockHandler $mock;

  /**
   * Requests sent to the mocked API.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * The current request time.
   */
  private int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->now = $this->container->get('datetime.time')->getRequestTime();

    $this->setTicketSettings(['date_field' => 'field_when']);

    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->setApiKey('secret');
  }

  /**
   * Merges settings into the ticket field's settings.
   *
   * @param array<string, string> $settings
   *   Field settings.
   */
  private function setTicketSettings(array $settings): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_ticket');
    $field->set('settings', $settings + $field->getSettings())->save();
  }

  /**
   * Sets the API key in settings.php and refreshes the services using it.
   *
   * @param string $key
   *   The key, or an empty string for none.
   */
  private function setApiKey(string $key): void {
    $this->setSetting('dagda_smeetz', ['api_key' => $key]);
    $this->container->set('settings', Settings::getInstance());
  }

  /**
   * Returns the sync service.
   */
  private function sync(): AvailabilitySyncInterface {
    return $this->container->get(AvailabilitySyncInterface::class);
  }

  /**
   * Creates a session with a ticket option starting at a time.
   *
   * @param string $option
   *   The option UUID.
   * @param int $start
   *   The start, as a Unix timestamp.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The saved entity.
   */
  private function session(string $option, int $start): EntityTest {
    return $this->createEntity([
      'field_ticket' => ['product_id' => '39161', 'option_uuid' => $option],
      'field_when' => gmdate('Y-m-d\TH:i:s', $start),
    ]);
  }

  /**
   * Builds a Smeetz Get Availabilities response.
   *
   * @param array<int, array{0: string, 1: int, 2: bool}> $slots
   *   Option UUID, UTC start and availability of each slot.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   The response.
   */
  private function response(array $slots): Response {
    $data = [];
    foreach ($slots as $i => [$option, $start, $available]) {
      $data[] = [
        'id' => $i + 1,
        'available' => $available,
        'dateTimeStart' => gmdate('Y-m-d\TH:i:s', $start),
        'localDateTimeStart' => gmdate('Y-m-d\TH:i:s', $start + 3600),
        'capacity' => 100,
        'optionId' => $option,
      ];
    }
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'data' => $data,
      'meta' => ['page' => 1, 'pageSize' => 100],
      'error' => NULL,
    ]));
  }

  /**
   * Returns the availability stored on an entity.
   */
  private function availability(EntityTest $entity): ?string {
    return EntityTest::load($entity->id())->get('field_ticket')->first()->get('availability')->getValue();
  }

  /**
   * Tests one bulk request for every upcoming session, shared options too.
   */
  public function testBulkSync(): void {
    $t1 = $this->now + 10 * 86400;
    $t2 = $t1 + 7200;
    $t3 = $t1 + 86400;
    $first = $this->session(self::OPTION_A, $t1);
    $second = $this->session(self::OPTION_A, $t2);
    $third = $this->session(self::OPTION_B, $t3);
    $past = $this->session(self::OPTION_C, $this->now - 86400);

    $this->mock->append($this->response([
      [self::OPTION_A, $t1, FALSE],
      [self::OPTION_A, $t2, TRUE],
      [self::OPTION_B, $t3, FALSE],
    ]));
    $result = $this->sync()->run();

    $this->assertTrue($result->success);
    $this->assertSame(3, $result->items);
    $this->assertSame(3, $result->changed);
    $this->assertSame(1, $result->requests);
    $this->assertCount(1, $this->history);

    parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
    $this->assertSame(self::OPTION_A . ',' . self::OPTION_B, $query['option_ids'], 'Options are deduplicated; past sessions are not asked for.');
    $this->assertSame('100', $query['page_size']);
    $this->assertSame('secret', $this->history[0]['request']->getHeaderLine('x-public-api-key'));
    $this->assertStringStartsWith('https://services.test.smeetz.com/public/v1/availability', (string) $this->history[0]['request']->getUri());

    $this->assertSame(SmeetzTicketItem::SOLD_OUT, $this->availability($first));
    $this->assertSame(SmeetzTicketItem::AVAILABLE, $this->availability($second));
    $this->assertSame(SmeetzTicketItem::SOLD_OUT, $this->availability($third));
    $this->assertNull($this->availability($past));

    $state = $this->container->get('state');
    $this->assertSame($this->now, $state->get(AvailabilitySyncInterface::STATE_LAST_SUCCESS));
    $this->assertSame(1, $state->get(AvailabilitySyncInterface::STATE_LAST_REQUESTS));
    $this->assertTrue($this->sync()->isFresh());
  }

  /**
   * Tests that every page is read until a short one.
   */
  public function testPagination(): void {
    $start = $this->now + 86400;
    $session = $this->session(self::OPTION_A, $start);
    $other = [];
    for ($i = 0; $i < 100; $i++) {
      $other[] = [self::OPTION_B, $start + ($i + 1) * 3600, TRUE];
    }
    $this->mock->append($this->response($other), $this->response([[self::OPTION_A, $start, FALSE]]));

    $result = $this->sync()->run();
    $this->assertSame(2, $result->requests);
    parse_str($this->history[1]['request']->getUri()->getQuery(), $query);
    $this->assertSame('2', $query['page']);
    $this->assertSame(SmeetzTicketItem::SOLD_OUT, $this->availability($session));
  }

  /**
   * Tests that HTTP 429 stops the run and keeps the last known statuses.
   */
  public function testRateLimit(): void {
    $session = $this->session(self::OPTION_A, $this->now + 86400);
    $session->get('field_ticket')->first()->set('availability', SmeetzTicketItem::AVAILABLE);
    $session->save();

    $this->mock->append(new Response(429));
    $result = $this->sync()->run();

    $this->assertFalse($result->success);
    $this->assertStringContainsString('429', $result->message);
    $this->assertCount(1, $this->history, 'No retry loop.');
    $this->assertSame(SmeetzTicketItem::AVAILABLE, $this->availability($session));
    $this->assertNull($this->container->get('state')->get(AvailabilitySyncInterface::STATE_LAST_SUCCESS));
  }

  /**
   * Tests that nothing is asked outside the festival period.
   */
  public function testNoUpcomingSession(): void {
    $this->session(self::OPTION_A, $this->now - 3600);
    $result = $this->sync()->run();
    $this->assertTrue($result->success);
    $this->assertSame(0, $result->requests);
    $this->assertCount(0, $this->history);
  }

  /**
   * Tests that nothing runs without an API key.
   */
  public function testNoApiKey(): void {
    $this->setApiKey('');
    $this->session(self::OPTION_A, $this->now + 86400);
    $result = $this->sync()->run();
    $this->assertFalse($result->success);
    $this->assertCount(0, $this->history);
  }

  /**
   * Tests that an unchanged status does not save the entity.
   */
  public function testUnchangedStatusIsNotSaved(): void {
    $start = $this->now + 86400;
    $session = $this->session(self::OPTION_A, $start);
    $session->get('field_ticket')->first()->setValue([
      'product_id' => '39161',
      'option_uuid' => self::OPTION_A,
      'availability' => SmeetzTicketItem::AVAILABLE,
      'checked' => 123,
    ]);
    $session->save();

    $this->mock->append($this->response([[self::OPTION_A, $start, TRUE]]));
    $result = $this->sync()->run();
    $this->assertSame(0, $result->changed);
    $item = EntityTest::load($session->id())->get('field_ticket')->first();
    $this->assertEquals(123, $item->get('checked')->getValue(), 'The entity was not saved.');
  }

  /**
   * Tests that a slot at another time leaves the status as it is.
   */
  public function testUnmatchedSlot(): void {
    $start = $this->now + 86400;
    $session = $this->session(self::OPTION_A, $start);
    $this->mock->append($this->response([[self::OPTION_A, $start + 7200, FALSE]]));
    $this->sync()->run();
    $this->assertNull($this->availability($session));
  }

  /**
   * Tests the copy into a list field named in the field settings.
   */
  public function testStatusField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'entity_test',
      'type' => 'list_string',
      'settings' => ['allowed_values' => ['available' => 'Disponible', 'full' => 'Complet']],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_status',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
    $this->setTicketSettings([
      'status_field' => 'field_status',
      'status_available' => 'available',
      'status_sold_out' => 'full',
    ]);

    $start = $this->now + 86400;
    $session = $this->session(self::OPTION_A, $start);
    $this->mock->append($this->response([[self::OPTION_A, $start, FALSE]]));
    $this->sync()->run();
    $this->assertSame('full', EntityTest::load($session->id())->get('field_status')->value);
  }

  /**
   * Tests that cron honours the interval.
   */
  public function testCronInterval(): void {
    $this->session(self::OPTION_A, $this->now + 86400);
    $state = $this->container->get('state');
    $state->set(AvailabilitySyncInterface::STATE_LAST_ATTEMPT, $this->now - 60);
    $this->container->get('cron')->run();
    $this->assertCount(0, $this->history, 'Too soon: no request.');

    $state->set(AvailabilitySyncInterface::STATE_LAST_ATTEMPT, $this->now - 3600);
    $this->mock->append($this->response([]));
    $this->container->get('cron')->run();
    $this->assertCount(1, $this->history);
  }

}

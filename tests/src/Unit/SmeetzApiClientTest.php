<?php

declare(strict_types=1);

namespace Drupal\Tests\smeetz_ticketing\Unit;

use Drupal\Core\Site\Settings;
use Drupal\smeetz_ticketing\Api\SmeetzApiClient;
use Drupal\smeetz_ticketing\Exception\SmeetzApiException;
use Drupal\smeetz_ticketing\SmeetzUrls;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Smeetz API client.
 */
#[CoversClass(SmeetzApiClient::class)]
#[Group('smeetz_ticketing')]
final class SmeetzApiClientTest extends UnitTestCase {

  /**
   * Requests sent.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $history = [];

  /**
   * Builds a client answering with the given responses.
   *
   * @param array<int, mixed> $responses
   *   Responses or exceptions, in order.
   * @param string $key
   *   The API key.
   */
  private function client(array $responses, string $key = 'secret'): SmeetzApiClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new SmeetzApiClient(
      new Client(['handler' => $stack]),
      new Settings(['smeetz_ticketing' => ['api_key' => $key]]),
      new SmeetzUrls($this->getConfigFactoryStub([
        'smeetz_ticketing.settings' => ['environment' => 'live', 'group_id' => ''],
      ])),
    );
  }

  /**
   * Builds an empty successful response.
   */
  private function empty(): Response {
    return new Response(200, [], '{"data":[],"meta":{},"error":null}');
  }

  /**
   * Tests that options go in chunks of 50.
   */
  public function testChunks(): void {
    $options = [];
    for ($i = 0; $i < 120; $i++) {
      $options[] = sprintf('00000000-0000-4000-8000-%012d', $i);
    }
    $client = $this->client([$this->empty(), $this->empty(), $this->empty()]);
    $this->assertSame([], $client->availabilities($options, 0, 86400));
    $this->assertSame(3, $client->requestCount());
    parse_str($this->history[2]['request']->getUri()->getQuery(), $query);
    $this->assertCount(20, explode(',', $query['option_ids']));
    $this->assertSame('1970-01-01T00:00:00Z', $query['date_time_start']);
    $this->assertStringStartsWith('https://services-live.smeetz.com/', (string) $this->history[0]['request']->getUri());
  }

  /**
   * Tests that a slot is read with its UTC start.
   */
  public function testSlot(): void {
    $body = [
      'data' => [
        [
          'optionId' => 'AAAAAAAA-0000-4000-8000-000000000001',
          'dateTimeStart' => '2027-03-12T19:00:00',
          'available' => FALSE,
        ],
        ['broken' => TRUE],
      ],
      'meta' => [],
      'error' => NULL,
    ];
    $client = $this->client([new Response(200, [], json_encode($body))]);
    $slots = $client->availabilities(['aaaaaaaa-0000-4000-8000-000000000001'], 0, 1);
    $this->assertCount(1, $slots);
    $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $slots[0]->optionId);
    $this->assertSame(gmmktime(19, 0, 0, 3, 12, 2027), $slots[0]->start);
    $this->assertFalse($slots[0]->available);
  }

  /**
   * Tests the errors that stop a sync.
   */
  public function testErrors(): void {
    $request = new Request('GET', 'https://services-live.smeetz.com');
    foreach ([
      new Response(500),
      new Response(200, [], 'not json'),
      new Response(200, [], '{"error":"x"}'),
      new ConnectException('down', $request),
    ] as $response) {
      try {
        $this->client([$response])->availabilities(['a'], 0, 1);
        $this->fail('An exception was expected.');
      }
      catch (SmeetzApiException $e) {
        $this->assertNotSame('', $e->getMessage());
      }
    }

    $this->expectException(SmeetzApiException::class);
    $this->client([], '')->availabilities(['a'], 0, 1);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\dagda_smeetz\Unit;

use Drupal\dagda_smeetz\Enum\SmeetzEnvironment;
use Drupal\dagda_smeetz\SmeetzUrls;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Smeetz URLs built from the settings.
 */
#[CoversClass(SmeetzUrls::class)]
#[Group('dagda_smeetz')]
final class SmeetzUrlsTest extends UnitTestCase {

  /**
   * Builds the service with the given settings.
   *
   * @param array<string, string> $settings
   *   The dagda_smeetz.settings values.
   *
   * @return \Drupal\dagda_smeetz\SmeetzUrls
   *   The service.
   */
  private function urls(array $settings): SmeetzUrls {
    return new SmeetzUrls($this->getConfigFactoryStub(['dagda_smeetz.settings' => $settings]));
  }

  /**
   * Tests the loader and booking page URLs per environment.
   */
  #[DataProvider('providerEnvironments')]
  public function testUrls(string $environment, SmeetzEnvironment $expected, string $loader, string $page): void {
    $urls = $this->urls(['environment' => $environment, 'group_id' => '16342']);
    $this->assertSame($expected, $urls->environment());
    $this->assertSame($loader, $urls->loaderUrl());
    $this->assertSame($page, $urls->productPageUrl('39161'));
  }

  /**
   * Provides environment settings and the URLs they give.
   *
   * @return array<string, array{string, \Drupal\dagda_smeetz\Enum\SmeetzEnvironment, string, string}>
   *   Test cases.
   */
  public static function providerEnvironments(): array {
    return [
      'live' => [
        'live',
        SmeetzEnvironment::Live,
        'https://tracker.smeetz.com/smeetz-main-widget.js',
        'https://widget.smeetz.com/product/list/39161?productId=39161&orgid=16342',
      ],
      'test' => [
        'test',
        SmeetzEnvironment::Test,
        'https://test.tracker.smeetz.com/smeetz-main-widget.js',
        'https://test-widget.smeetz.com/product/list/39161?productId=39161&orgid=16342',
      ],
      'unknown falls back to test' => [
        'staging',
        SmeetzEnvironment::Test,
        'https://test.tracker.smeetz.com/smeetz-main-widget.js',
        'https://test-widget.smeetz.com/product/list/39161?productId=39161&orgid=16342',
      ],
    ];
  }

  /**
   * Tests that the booking page omits the group when none is set.
   */
  public function testProductPageWithoutGroup(): void {
    $urls = $this->urls(['environment' => 'live', 'group_id' => '']);
    $this->assertSame('', $urls->groupId());
    $this->assertSame('https://widget.smeetz.com/product/list/39161?productId=39161', $urls->productPageUrl('39161'));
  }

}

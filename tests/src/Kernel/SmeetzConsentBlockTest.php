<?php

declare(strict_types=1);

namespace Drupal\Tests\smeetz_ticketing\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\smeetz_ticketing\Plugin\Block\SmeetzConsentBlock;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Smeetz: booking choice" block.
 */
#[CoversClass(SmeetzConsentBlock::class)]
#[Group('smeetz_ticketing')]
#[RunTestsInSeparateProcesses]
final class SmeetzConsentBlockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'smeetz_ticketing', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['smeetz_ticketing']);
  }

  /**
   * Tests that the block shows only while the consent question is on.
   */
  public function testAccessFollowsSetting(): void {
    $block = $this->createBlock();
    $account = User::getAnonymousUser();

    $access = $block->access($account, TRUE);
    $this->assertFalse($access->isAllowed());
    $this->assertInstanceOf(CacheableDependencyInterface::class, $access);
    $this->assertContains('config:smeetz_ticketing.settings', $access->getCacheTags());

    $this->config('smeetz_ticketing.settings')->set('consent', TRUE)->save();
    $this->assertTrue($this->createBlock()->access($account, TRUE)->isAllowed());
  }

  /**
   * Tests the reset button, hidden until the JavaScript shows it.
   */
  public function testBuild(): void {
    $build = $this->createBlock()->build();
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $this->setRawContent($html);
    $button = $this->cssSelect('button[data-smeetz-consent-reset]')[0] ?? NULL;
    $this->assertNotNull($button);
    $this->assertSame('button', (string) $button['type']);
    $this->assertSame('hidden', (string) $button['hidden']);
    $this->assertStringContainsString('Change my choice for Smeetz bookings', $html);
    $this->assertContains('smeetz_ticketing/booking', $build['#attached']['library']);
  }

  /**
   * Creates the block plugin.
   *
   * @return \Drupal\smeetz_ticketing\Plugin\Block\SmeetzConsentBlock
   *   The block.
   */
  private function createBlock(): SmeetzConsentBlock {
    $block = $this->container->get(BlockManagerInterface::class)->createInstance('smeetz_ticketing_consent');
    assert($block instanceof SmeetzConsentBlock);
    return $block;
  }

}

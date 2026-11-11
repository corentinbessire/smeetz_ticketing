<?php

declare(strict_types=1);

namespace Drupal\Tests\dagda_smeetz\Kernel;

use Drupal\Core\Render\RendererInterface;
use Drupal\dagda_smeetz\Plugin\Field\FieldFormatter\SmeetzBookingButtonFormatter;
use Drupal\entity_test\Entity\EntityTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Smeetz booking button formatter.
 */
#[CoversClass(SmeetzBookingButtonFormatter::class)]
#[Group('dagda_smeetz')]
#[RunTestsInSeparateProcesses]
final class SmeetzBookingButtonFormatterTest extends SmeetzKernelTestBase {

  /**
   * Display options of the formatter under test.
   *
   * @var array<string, mixed>
   */
  private array $display = [
    'type' => 'dagda_smeetz_button',
    'label' => 'hidden',
    'settings' => [
      'label' => 'Réserver ma place',
      'date_field' => 'field_when',
      'lightbox' => TRUE,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.date')->set('timezone.default', 'Europe/Zurich')->save();
  }

  /**
   * Renders the ticket field of an entity.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   *
   * @return array{0: array<mixed>, 1: string}
   *   The render array and the HTML.
   */
  private function renderTicket(EntityTest $entity): array {
    $build = $entity->get('field_ticket')->view($this->display);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    return [$build, $html];
  }

  /**
   * Tests the enhanced button once a group ID is set.
   */
  public function testEnhancedButton(): void {
    $this->config('dagda_smeetz.settings')->set('group_id', '16342')->save();
    // 23:30 UTC on 11 March is already 12 March in Zurich.
    $entity = $this->createEntity([
      'field_ticket' => ['product_id' => '39161'],
      'field_when' => '2027-03-11T23:30:00',
    ]);
    [$build, $html] = $this->renderTicket($entity);

    $this->setRawContent($html);
    $link = $this->cssSelect('a.dagda-smeetz-button')[0] ?? NULL;
    $this->assertNotNull($link, 'The button is rendered.');
    $this->assertSame('https://test-widget.smeetz.com/product/list/39161?productId=39161&orgid=16342', (string) $link['href']);
    $this->assertSame('39161', (string) $link['data-smeetz-product']);
    $this->assertSame('2027-03-12', (string) $link['data-smeetz-date']);
    $this->assertSame('true', (string) $link['data-smeetz-lightbox']);
    $this->assertSame('dialog', (string) $link['aria-haspopup']);
    $this->assertSame('en', (string) $link['data-smeetz-lang']);
    $this->assertSame('_blank', (string) $link['target']);
    $this->assertStringContainsString('Réserver ma place', $html);
    $this->assertStringNotContainsString('new tab', $html);

    $this->assertContains('dagda_smeetz/booking', $build[0]['#attached']['library']);
    $this->assertSame([
      'groupId' => '16342',
      'loaderUrl' => 'https://test.tracker.smeetz.com/smeetz-main-widget.js',
    ], $build[0]['#attached']['drupalSettings']['dagdaSmeetz']);
    $this->assertContains('config:dagda_smeetz.settings', $build['#cache']['tags']);
  }

  /**
   * Tests the plain link while no group ID is set.
   */
  public function testPlainLinkWithoutGroup(): void {
    $entity = $this->createEntity(['field_ticket' => ['product_id' => '39161']]);
    [$build, $html] = $this->renderTicket($entity);

    $this->setRawContent($html);
    $link = $this->cssSelect('a.dagda-smeetz-button')[0] ?? NULL;
    $this->assertNotNull($link);
    $this->assertSame('https://test-widget.smeetz.com/product/list/39161?productId=39161', (string) $link['href']);
    $this->assertEmpty((string) $link['data-smeetz-product']);
    $this->assertStringContainsString('opens Smeetz in a new tab', $html);
    $this->assertArrayNotHasKey('#attached', $build[0]);
    $this->assertContains('config:dagda_smeetz.settings', $build['#cache']['tags']);
  }

  /**
   * Tests the live environment, a date-only value and no date at all.
   */
  public function testLiveAndDates(): void {
    $this->config('dagda_smeetz.settings')
      ->set('environment', 'live')
      ->set('group_id', '16342')
      ->save();

    $entity = $this->createEntity(['field_ticket' => ['product_id' => '39161']]);
    [$build, $html] = $this->renderTicket($entity);
    $this->setRawContent($html);
    $link = $this->cssSelect('a.dagda-smeetz-button')[0];
    $this->assertEmpty((string) $link['data-smeetz-date'], 'No date when the date field is empty.');
    $this->assertStringStartsWith('https://widget.smeetz.com/', (string) $link['href']);
    $this->assertSame('https://tracker.smeetz.com/smeetz-main-widget.js', $build[0]['#attached']['drupalSettings']['dagdaSmeetz']['loaderUrl']);

    $this->display['settings']['lightbox'] = FALSE;
    $this->display['settings']['date_field'] = '';
    $entity->set('field_when', '2027-03-11T10:00:00')->save();
    [, $html] = $this->renderTicket($entity);
    $this->setRawContent($html);
    $link = $this->cssSelect('a.dagda-smeetz-button')[0];
    $this->assertEmpty((string) $link['data-smeetz-date'], 'No date when no date field is set.');
    $this->assertSame('false', (string) $link['data-smeetz-lightbox']);
  }

  /**
   * Tests that the formatter depends on the date field it reads.
   */
  public function testDependencies(): void {
    $display = $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent('field_ticket', $this->display);
    $display->save();
    $this->assertContains('field.field.entity_test.entity_test.field_when', $display->getDependencies()['config']);

    $this->container->get('entity_type.manager')->getStorage('field_config')
      ->load('entity_test.entity_test.field_when')->delete();
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')
      ->load('entity_test.entity_test.default');
    $this->assertSame('', $display->getComponent('field_ticket')['settings']['date_field']);
  }

}

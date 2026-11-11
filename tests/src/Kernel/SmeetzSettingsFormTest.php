<?php

declare(strict_types=1);

namespace Drupal\Tests\smeetz_ticketing\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\smeetz_ticketing\Form\SmeetzSettingsForm;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Smeetz settings form.
 */
#[CoversClass(SmeetzSettingsForm::class)]
#[Group('smeetz_ticketing')]
#[RunTestsInSeparateProcesses]
final class SmeetzSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['smeetz_ticketing', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['smeetz_ticketing']);
  }

  /**
   * Submits the form with the given values.
   *
   * @param array<string, string> $values
   *   Form values.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The submitted form state.
   */
  private function submit(array $values): FormStateInterface {
    $form_state = (new FormState())->setValues($values + ['op' => 'Save configuration']);
    $this->container->get('form_builder')->submitForm(SmeetzSettingsForm::class, $form_state);
    return $form_state;
  }

  /**
   * Tests that valid values are saved.
   */
  public function testSave(): void {
    $this->assertSame('test', $this->config('smeetz_ticketing.settings')->get('environment'));

    $form_state = $this->submit(['environment' => 'live', 'group_id' => '16342']);
    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('smeetz_ticketing.settings');
    $this->assertSame('live', $config->get('environment'));
    $this->assertSame('16342', $config->get('group_id'));
  }

  /**
   * Tests that a group ID that is not a number is refused.
   */
  public function testInvalidGroupId(): void {
    $form_state = $this->submit(['environment' => 'test', 'group_id' => 'abc']);
    $this->assertArrayHasKey('group_id', $form_state->getErrors());
    $saved = $this->container->get('config.storage')->read('smeetz_ticketing.settings');
    $this->assertSame('', $saved['group_id']);
  }

  /**
   * Tests the consent settings and the privacy link check.
   */
  public function testConsent(): void {
    $config = $this->config('smeetz_ticketing.settings');
    $this->assertFalse($config->get('consent'));

    $form_state = $this->submit([
      'environment' => 'test',
      'group_id' => '',
      'consent' => '1',
      'privacy_url' => '/confidentialite',
    ]);
    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('smeetz_ticketing.settings');
    $this->assertTrue($config->get('consent'));
    $this->assertSame('/confidentialite', $config->get('privacy_url'));

    $form_state = $this->submit([
      'environment' => 'test',
      'group_id' => '',
      'consent' => '1',
      'privacy_url' => 'javascript:alert(1)',
    ]);
    $this->assertArrayHasKey('privacy_url', $form_state->getErrors());
  }

  /**
   * Tests that the post update adds the consent settings to older installs.
   */
  public function testConsentPostUpdate(): void {
    $this->config('smeetz_ticketing.settings')
      ->clear('consent')
      ->clear('privacy_url')
      ->save(TRUE);
    require_once dirname(__DIR__, 3) . '/smeetz_ticketing.post_update.php';
    smeetz_ticketing_post_update_consent_settings();
    $config = $this->config('smeetz_ticketing.settings');
    $this->assertFalse($config->get('consent'));
    $this->assertSame('', $config->get('privacy_url'));
  }

  /**
   * Tests that the route requires the module's permission.
   */
  public function testRouteAccess(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('smeetz_ticketing.settings');
    $this->assertSame('administer smeetz_ticketing', $route->getRequirement('_permission'));
    $this->assertSame('/admin/config/services/smeetz', $route->getPath());
  }

}

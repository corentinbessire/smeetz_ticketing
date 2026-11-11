<?php

declare(strict_types=1);

namespace Drupal\Tests\dagda_smeetz\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dagda_smeetz\Form\SmeetzSettingsForm;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Smeetz settings form.
 */
#[CoversClass(SmeetzSettingsForm::class)]
#[Group('dagda_smeetz')]
#[RunTestsInSeparateProcesses]
final class SmeetzSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dagda_smeetz', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['dagda_smeetz']);
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
    $this->assertSame('test', $this->config('dagda_smeetz.settings')->get('environment'));

    $form_state = $this->submit(['environment' => 'live', 'group_id' => '16342']);
    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('dagda_smeetz.settings');
    $this->assertSame('live', $config->get('environment'));
    $this->assertSame('16342', $config->get('group_id'));
  }

  /**
   * Tests that a group ID that is not a number is refused.
   */
  public function testInvalidGroupId(): void {
    $form_state = $this->submit(['environment' => 'test', 'group_id' => 'abc']);
    $this->assertArrayHasKey('group_id', $form_state->getErrors());
    $saved = $this->container->get('config.storage')->read('dagda_smeetz.settings');
    $this->assertSame('', $saved['group_id']);
  }

  /**
   * Tests that the route requires the module's permission.
   */
  public function testRouteAccess(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('dagda_smeetz.settings');
    $this->assertSame('administer dagda_smeetz', $route->getRequirement('_permission'));
    $this->assertSame('/admin/config/services/smeetz', $route->getPath());
  }

}

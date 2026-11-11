<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A button that forgets the visitor's Smeetz choice and Smeetz's own data.
 *
 * Shown only while "Ask before loading Smeetz" is on. The next booking click
 * asks again.
 */
#[Block(
  id: 'smeetz_ticketing_consent',
  admin_label: new TranslatableMarkup('Smeetz: booking choice', [], ['context' => 'Smeetz']),
  category: new TranslatableMarkup('Smeetz', [], ['context' => 'Smeetz']),
)]
final class SmeetzConsentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $config = $this->configFactory->get('smeetz_ticketing.settings');
    return AccessResult::allowedIf((bool) $config->get('consent'))
      ->addCacheableDependency($config);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Change my choice for Smeetz bookings', [], ['context' => 'Smeetz']),
      '#attributes' => [
        'type' => 'button',
        'class' => ['smeetz-ticketing-consent-reset'],
        'data-smeetz-consent-reset' => '',
        'hidden' => 'hidden',
      ],
      '#attached' => ['library' => ['smeetz_ticketing/booking']],
    ];
  }

}

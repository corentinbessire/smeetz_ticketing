<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dagda_smeetz\Enum\SmeetzEnvironment;

/**
 * Builds Smeetz URLs from the module settings.
 */
final class SmeetzUrls implements SmeetzUrlsInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function environment(): SmeetzEnvironment {
    $value = (string) $this->configFactory->get('dagda_smeetz.settings')->get('environment');
    return SmeetzEnvironment::tryFrom($value) ?? SmeetzEnvironment::Test;
  }

  /**
   * {@inheritdoc}
   */
  public function groupId(): string {
    return (string) $this->configFactory->get('dagda_smeetz.settings')->get('group_id');
  }

  /**
   * {@inheritdoc}
   */
  public function loaderUrl(): string {
    return $this->environment()->loaderUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function productPageUrl(string $product_id): string {
    $query = ['productId' => $product_id];
    if ($this->groupId() !== '') {
      $query['orgid'] = $this->groupId();
    }
    return $this->environment()->widgetBaseUrl()
      . '/product/list/' . rawurlencode($product_id)
      . '?' . http_build_query($query);
  }

}

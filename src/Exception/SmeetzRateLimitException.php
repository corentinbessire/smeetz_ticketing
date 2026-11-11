<?php

declare(strict_types=1);

namespace Drupal\dagda_smeetz\Exception;

/**
 * Thrown when Smeetz answers HTTP 429: stop and wait for the next run.
 */
final class SmeetzRateLimitException extends SmeetzApiException {}

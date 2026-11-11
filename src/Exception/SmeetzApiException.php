<?php

declare(strict_types=1);

namespace Drupal\smeetz_ticketing\Exception;

/**
 * Thrown when the Smeetz API cannot be reached or answers with an error.
 */
class SmeetzApiException extends \RuntimeException {}

<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api\Exception;

/**
 * Raised when the plugin is not (correctly) configured, e.g. missing e-mail or
 * API key. Never carries the secret itself.
 */
final class FastBillConfigurationException extends FastBillException
{
}

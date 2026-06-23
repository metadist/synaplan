<?php

namespace App\AI\Exception;

/**
 * Raised when an in-flight provider generation is aborted on purpose by the
 * user (global Stop or a per-step Stop), as opposed to failing on its own.
 *
 * Distinct from {@see ProviderException} so callers can render a neutral
 * "cancelled" state instead of a "generation failed / try another model"
 * error.
 */
class ProviderCancelledException extends ProviderException
{
}

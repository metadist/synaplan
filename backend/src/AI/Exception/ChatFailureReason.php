<?php

declare(strict_types=1);

namespace App\AI\Exception;

/**
 * Coarse, user-facing category of a failed chat / sorting / generation call.
 *
 * Derived only from structured provider data (HTTP status, error type/code,
 * exception class, ProviderException context). Never from free-text matching.
 */
enum ChatFailureReason: string
{
    case SchemaMismatch = 'schema_mismatch';
    case ContextLengthExceeded = 'context_length_exceeded';
    case RequestTooLarge = 'request_too_large';
    case RateLimited = 'rate_limited';
    case QuotaExceeded = 'quota_exceeded';
    case AuthFailed = 'auth_failed';
    case ModelUnavailable = 'model_unavailable';
    case ContentFiltered = 'content_filtered';
    case Timeout = 'timeout';
    case UpstreamUnavailable = 'upstream_unavailable';
    case Unknown = 'unknown';

    /**
     * Whether the user should be offered "try again with another model".
     *
     * Auth and quota failures are operator/account problems — switching the
     * model will not help the user and would hide the real cause.
     */
    public function suggestsOtherModel(): bool
    {
        return match ($this) {
            self::AuthFailed, self::QuotaExceeded => false,
            default => true,
        };
    }

    public function translationKey(): string
    {
        return 'reason.'.$this->value;
    }
}

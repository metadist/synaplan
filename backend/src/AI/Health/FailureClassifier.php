<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Exception\StructuredOutputViolationException;
use App\Service\Exception\StreamCancelledException;

/**
 * Sorts a failed AI call into a {@see FailureKind}.
 *
 * Order matters. The upstream HTTP status is the most reliable signal, but it
 * is only present when the provider actually answered — SDK wrappers, cURL
 * errors and our own guard clauses all arrive with code 0. Message matching
 * therefore runs first for the cases that are unambiguous in text, then the
 * status decides, and only what is left over falls back to transient.
 *
 * Defaulting to transient is deliberate: an unknown failure must never be able
 * to retire a model on its own.
 */
final readonly class FailureClassifier
{
    /**
     * Provider wording for "this model does not exist (any more)". Matched
     * case-insensitively against the exception message.
     */
    private const PERMANENT_PATTERNS = [
        'model_not_found',
        'model not found',
        // ProviderException::noModelAvailable() puts the rejected model name
        // between the words: "Model 'gpt-5' not found for provider 'openai'".
        'not found for provider',
        'model available for provider',
        'unknown model',
        'invalid model',
        'model does not exist',
        'does not exist or you do not have access',
        'no longer available',
        'no longer supported',
        'has been retired',
        'is retired',
        // "deprecated" alone matches "parameter X is deprecated" on a 400, which
        // is a request problem, not a retired model. Keep the phrasing that
        // providers use when the model itself is going away.
        'model is deprecated',
        'has been deprecated',
        'was deprecated',
        'deprecated and will be removed',
        'deprecated model',
        'decommissioned',
        'discontinued',
        'has been sunset',
        'is sunset',
        'sunsetting',
        'not_found_error',
    ];

    /** Credential, entitlement and billing problems — every model of the provider is affected. */
    private const CREDENTIAL_PATTERNS = [
        'api key',
        'api_key',
        'apikey',
        'invalid_api_key',
        'authentication_error',
        'authentication failed',
        'unauthorized',
        'unauthenticated',
        'permission_error',
        'permission denied',
        'invalid authentication',
        'incorrect api key',
        'out of credits',
        'insufficient_quota',
        'insufficient credits',
        'insufficient balance',
        'billing',
        'payment required',
        'quota exceeded',
        'exceeded your current quota',
    ];

    /** The request was at fault, not the model. */
    private const USER_ERROR_PATTERNS = [
        'content blocked',
        'content_filter',
        'content_policy',
        'safety filter',
        'safety system',
        'safety settings',
        'safety ratings',
        'content safety',
        'blocked by safety',
        'context length',
        'context_length_exceeded',
        'maximum context',
        'too many tokens',
        'prompt is too long',
        'string too long',
    ];

    /** Recoverable on its own — never a reason to switch a model off. */
    private const TRANSIENT_PATTERNS = [
        'rate limit',
        'rate_limit',
        'too many requests',
        'overloaded',
        'capacity',
        'timeout',
        'timed out',
        'temporarily unavailable',
        'service unavailable',
        'circuit breaker',
        'connection refused',
        'could not resolve host',
        'network',
        'try again',
    ];

    public function classify(\Throwable $error): FailureKind
    {
        if ($error instanceof ProviderCancelledException || $error instanceof StreamCancelledException) {
            return FailureKind::Cancelled;
        }

        // The provider answered promptly and rejected the model's OWN output
        // against the schema WE asked for. That is a prompt/schema problem on
        // the request side (Groq's wording: "Please adjust your prompt"), not
        // model health — counting it would let a burst of echoed input fields
        // switch off the routing model for everyone.
        if ($error instanceof StructuredOutputViolationException) {
            return FailureKind::UserError;
        }

        // ProviderException::contentBlocked() carries the provider's own block
        // reason. Trust that over any text matching — a blocked prompt is a
        // property of the request, never of the model.
        if ($error instanceof ProviderException) {
            $context = $error->getContext() ?? [];
            if (isset($context['block_reason'])) {
                return FailureKind::UserError;
            }
            // missingApiKey() documents the env var it wants set.
            if (isset($context['env_var'])) {
                return FailureKind::Credential;
            }
            // noModelAvailable() names the model the provider rejected.
            if (isset($context['requested_model']) || isset($context['suggested_models'])) {
                return FailureKind::Permanent;
            }
        }

        $message = strtolower($error->getMessage());

        // Text first: a provider that answers "model X was deprecated" with a
        // generic 400 would otherwise be filed as a user error.
        if (self::matchesAny($message, self::PERMANENT_PATTERNS)) {
            return FailureKind::Permanent;
        }
        if (self::matchesAny($message, self::CREDENTIAL_PATTERNS)) {
            return FailureKind::Credential;
        }
        if (self::matchesAny($message, self::TRANSIENT_PATTERNS)) {
            return FailureKind::Transient;
        }
        if (self::matchesAny($message, self::USER_ERROR_PATTERNS)) {
            return FailureKind::UserError;
        }

        $status = $error instanceof ProviderException ? $error->getUpstreamStatus() : null;
        if (null !== $status) {
            return self::fromStatus($status);
        }

        return FailureKind::Transient;
    }

    /**
     * Map an upstream HTTP status onto a failure kind.
     *
     * 429 is transient by definition — treating a rate limit as a model defect
     * is exactly how an automation switches off the busiest model during a
     * traffic spike. {@see \App\AI\Credential\ProviderKeyValidator} already
     * encodes the same rule for key validation.
     */
    private static function fromStatus(int $status): FailureKind
    {
        return match (true) {
            401 === $status, 403 === $status, 402 === $status => FailureKind::Credential,
            404 === $status, 410 === $status => FailureKind::Permanent,
            429 === $status, 408 === $status, 409 === $status, 425 === $status => FailureKind::Transient,
            $status >= 500 => FailureKind::Transient,
            $status >= 400 => FailureKind::UserError,
            default => FailureKind::Transient,
        };
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

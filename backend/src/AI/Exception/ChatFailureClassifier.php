<?php

declare(strict_types=1);

namespace App\AI\Exception;

use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Maps a failed AI call onto a {@see ChatFailureReason} from structured data
 * only: HTTP status, provider error type/code, exception class, and
 * {@see ProviderException} context keys. No free-text / regex matching.
 *
 * When nothing structured is available the result is {@see ChatFailureReason::Unknown}
 * — guessing from the message would leak implementation details into the
 * user-facing category and is deliberately refused.
 */
final readonly class ChatFailureClassifier
{
    /**
     * Provider error codes that mean "the model output did not match the
     * requested JSON schema" (Groq structured output, OpenAI json_schema).
     *
     * @var list<string>
     */
    private const SCHEMA_CODES = [
        'json_validate_failed',
        'json_schema_validation_failed',
        'schema_validation_failed',
    ];

    /**
     * Provider error codes that mean the prompt / context window overflowed.
     *
     * @var list<string>
     */
    private const CONTEXT_LENGTH_CODES = [
        'context_length_exceeded',
        'max_tokens',
        'max_tokens_exceeded',
        'token_limit_exceeded',
    ];

    /**
     * Provider error codes that mean the named model is gone or unknown.
     *
     * @var list<string>
     */
    private const MODEL_UNAVAILABLE_CODES = [
        'model_not_found',
        'model_decommissioned',
        'not_found_error',
    ];

    /**
     * Provider error codes that mean the account is out of credits / quota.
     *
     * @var list<string>
     */
    private const QUOTA_CODES = [
        'insufficient_quota',
        'billing_not_active',
        'quota_exceeded',
    ];

    /**
     * Provider error codes / types that mean a safety filter blocked the call.
     *
     * @var list<string>
     */
    private const CONTENT_FILTER_CODES = [
        'content_filter',
        'content_policy_violation',
        'safety',
    ];

    public function classify(\Throwable $error): ChatFailureReason
    {
        if ($error instanceof ProviderException) {
            $fromContext = $this->fromProviderContext($error);
            if (null !== $fromContext) {
                return $fromContext;
            }
        }

        $fromClass = $this->fromExceptionClass($error);
        if (null !== $fromClass) {
            return $fromClass;
        }

        $previous = $error->getPrevious();
        if (null !== $previous) {
            $fromPreviousClass = $this->fromExceptionClass($previous);
            if (null !== $fromPreviousClass) {
                return $fromPreviousClass;
            }

            $fromPreviousSdk = $this->fromOpenAiError($previous);
            if (null !== $fromPreviousSdk) {
                return $fromPreviousSdk;
            }
        }

        $fromSdk = $this->fromOpenAiError($error);
        if (null !== $fromSdk) {
            return $fromSdk;
        }

        $status = $this->resolveStatus($error);
        if (null !== $status) {
            return $this->fromStatus($status);
        }

        return ChatFailureReason::Unknown;
    }

    private function fromProviderContext(ProviderException $error): ?ChatFailureReason
    {
        $context = $error->getContext() ?? [];

        if (isset($context['block_reason'])) {
            return ChatFailureReason::ContentFiltered;
        }

        if (isset($context['env_var'])) {
            return ChatFailureReason::AuthFailed;
        }

        if (isset($context['requested_model']) || isset($context['suggested_models'])) {
            return ChatFailureReason::ModelUnavailable;
        }

        $errorCode = $this->stringify($context['error_code'] ?? null);
        $errorType = $this->stringify($context['error_type'] ?? null);

        $fromCode = $this->fromErrorCode($errorCode);
        if (null !== $fromCode) {
            return $fromCode;
        }

        $fromType = $this->fromErrorType($errorType);
        if (null !== $fromType) {
            return $fromType;
        }

        $status = $error->getUpstreamStatus();
        if (null === $status && isset($context['status_code']) && is_numeric($context['status_code'])) {
            $status = (int) $context['status_code'];
        }

        if (null !== $status) {
            return $this->fromStatus($status);
        }

        return null;
    }

    private function fromExceptionClass(\Throwable $error): ?ChatFailureReason
    {
        if ($error instanceof RateLimitException) {
            return ChatFailureReason::RateLimited;
        }

        if ($error instanceof ServerException || $error instanceof TransporterException) {
            return ChatFailureReason::UpstreamUnavailable;
        }

        if ($error instanceof TimeoutExceptionInterface) {
            return ChatFailureReason::Timeout;
        }

        if ($error instanceof TransportExceptionInterface) {
            return ChatFailureReason::UpstreamUnavailable;
        }

        return null;
    }

    private function fromOpenAiError(\Throwable $error): ?ChatFailureReason
    {
        if (!$error instanceof ErrorException) {
            return null;
        }

        $fromCode = $this->fromErrorCode($this->stringify($error->getErrorCode()));
        if (null !== $fromCode) {
            return $fromCode;
        }

        $fromType = $this->fromErrorType($this->stringify($error->getErrorType()));
        if (null !== $fromType) {
            return $fromType;
        }

        return $this->fromStatus($error->getStatusCode());
    }

    private function fromErrorCode(?string $code): ?ChatFailureReason
    {
        if (null === $code || '' === $code) {
            return null;
        }

        $normalized = strtolower($code);

        if (in_array($normalized, self::SCHEMA_CODES, true)) {
            return ChatFailureReason::SchemaMismatch;
        }
        if (in_array($normalized, self::CONTEXT_LENGTH_CODES, true)) {
            return ChatFailureReason::ContextLengthExceeded;
        }
        if (in_array($normalized, self::MODEL_UNAVAILABLE_CODES, true)) {
            return ChatFailureReason::ModelUnavailable;
        }
        if (in_array($normalized, self::QUOTA_CODES, true)) {
            return ChatFailureReason::QuotaExceeded;
        }
        if (in_array($normalized, self::CONTENT_FILTER_CODES, true)) {
            return ChatFailureReason::ContentFiltered;
        }

        return match ($normalized) {
            'rate_limit_exceeded', 'rate_limit_error' => ChatFailureReason::RateLimited,
            'invalid_api_key', 'authentication_error', 'permission_error' => ChatFailureReason::AuthFailed,
            default => null,
        };
    }

    private function fromErrorType(?string $type): ?ChatFailureReason
    {
        if (null === $type || '' === $type) {
            return null;
        }

        return match (strtolower($type)) {
            'insufficient_quota' => ChatFailureReason::QuotaExceeded,
            'authentication_error', 'permission_error' => ChatFailureReason::AuthFailed,
            'not_found_error' => ChatFailureReason::ModelUnavailable,
            'rate_limit_error' => ChatFailureReason::RateLimited,
            'overloaded_error', 'api_error' => ChatFailureReason::UpstreamUnavailable,
            default => null,
        };
    }

    private function fromStatus(int $status): ChatFailureReason
    {
        return match (true) {
            401 === $status, 403 === $status => ChatFailureReason::AuthFailed,
            402 === $status => ChatFailureReason::QuotaExceeded,
            404 === $status, 410 === $status => ChatFailureReason::ModelUnavailable,
            408 === $status => ChatFailureReason::Timeout,
            413 === $status => ChatFailureReason::RequestTooLarge,
            429 === $status => ChatFailureReason::RateLimited,
            $status >= 500 => ChatFailureReason::UpstreamUnavailable,
            default => ChatFailureReason::Unknown,
        };
    }

    private function resolveStatus(\Throwable $error): ?int
    {
        if ($error instanceof ProviderException) {
            return $error->getUpstreamStatus();
        }

        if ($error instanceof ErrorException) {
            $status = $error->getStatusCode();

            return $status >= 400 ? $status : null;
        }

        $code = $error->getCode();

        return $code >= 400 && $code <= 599 ? $code : null;
    }

    private function stringify(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return '' !== $trimmed ? $trimmed : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}

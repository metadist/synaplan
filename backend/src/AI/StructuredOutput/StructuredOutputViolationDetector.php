<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

use App\AI\Exception\StructuredOutputViolationException;
use OpenAI\Exceptions\ErrorException;

/**
 * Recognises "the model's generation failed the provider's schema check" in
 * the errors the OpenAI-compatible SDK throws, and turns it into the typed
 * {@see StructuredOutputViolationException} — with the rejected output
 * attached, because that is what makes the failure recoverable.
 *
 * Groq is the documented case: best-effort structured output answers HTTP 400
 * `{"error": {"code": "json_validate_failed", "message": "Generated JSON does
 * not match the expected schema. …", "failed_generation": "<model output>"}}`.
 * Observed on 2026-09-03 even with `strict: true` on `openai/gpt-oss-120b`,
 * which the provider documents as constrained decoding — so the guard cannot
 * be "strict mode makes this impossible".
 *
 * The SDK's {@see ErrorException} exposes `message`/`type`/`code` but not the
 * rest of the error object, so `failed_generation` is re-read from the raw
 * response body it carries. Every step is best-effort: a body that cannot be
 * re-read still yields the typed exception, just without a salvage payload.
 */
final class StructuredOutputViolationDetector
{
    private const ERROR_CODE = 'json_validate_failed';

    /** Groq's wording; matched case-insensitively as a fallback when the code is missing. */
    private const MESSAGE_MARKER = 'generated json does not match the expected schema';

    public static function fromSdkError(\Throwable $error, string $providerName, ?StructuredOutputSchema $schema): ?StructuredOutputViolationException
    {
        if ($error instanceof StructuredOutputViolationException) {
            return $error;
        }

        if (!$error instanceof ErrorException) {
            return null;
        }

        $code = $error->getErrorCode();
        $message = $error->getErrorMessage();
        if (self::ERROR_CODE !== $code && !str_contains(strtolower($message), self::MESSAGE_MARKER)) {
            return null;
        }

        return new StructuredOutputViolationException(
            $providerName,
            $message,
            self::failedGenerationFrom($error),
            $schema?->name,
            $error,
        );
    }

    private static function failedGenerationFrom(ErrorException $error): ?string
    {
        try {
            $body = $error->response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $raw = $body->getContents();
        } catch (\Throwable) {
            return null;
        }

        if ('' === $raw) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $failed = is_array($decoded) ? ($decoded['error']['failed_generation'] ?? null) : null;

        return is_string($failed) && '' !== trim($failed) ? $failed : null;
    }
}

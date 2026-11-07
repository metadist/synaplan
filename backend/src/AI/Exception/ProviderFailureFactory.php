<?php

declare(strict_types=1);

namespace App\AI\Exception;

use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wraps an upstream SDK / HTTP failure in a {@see ProviderException} that
 * carries structured context (status, error type, error code, stage) so
 * {@see ChatFailureClassifier} can classify without reading free text.
 */
final readonly class ProviderFailureFactory
{
    /**
     * @param string $stage Call site label, e.g. 'chat', 'chat_stream', 'vision'
     */
    public function fromThrowable(\Throwable $e, string $provider, string $stage, ?string $messagePrefix = null): ProviderException
    {
        if ($e instanceof ProviderException) {
            return $this->enrichExisting($e, $stage);
        }

        $extracted = $this->extractStructuredFields($e);
        $status = $extracted['status_code'];
        $context = [
            'error_type' => $extracted['error_type'],
            'error_code' => $extracted['error_code'],
            'status_code' => $extracted['status_code'],
            'stage' => $stage,
        ];

        $prefix = $messagePrefix ?? (ucfirst($provider).' '.$stage.' error');
        $detail = $extracted['message'] ?? $e->getMessage();
        $message = $prefix.': '.$detail;

        return new ProviderException($message, $provider, $context, $status, $e);
    }

    /**
     * Build a {@see ProviderException} from already-parsed provider fields
     * (Anthropic / Google parse the error body themselves).
     *
     * @param array<string, mixed> $extraContext
     */
    public function fromParsed(
        string $message,
        string $provider,
        string $stage,
        int $status = 0,
        ?string $errorType = null,
        string|int|null $errorCode = null,
        array $extraContext = [],
        ?\Throwable $previous = null,
    ): ProviderException {
        $context = array_merge($extraContext, [
            'error_type' => $errorType,
            'error_code' => $errorCode,
            'status_code' => $status > 0 ? $status : null,
            'stage' => $stage,
        ]);

        return new ProviderException($message, $provider, $context, $status, $previous);
    }

    private function enrichExisting(ProviderException $e, string $stage): ProviderException
    {
        $context = $e->getContext() ?? [];
        if (!isset($context['stage'])) {
            $context['stage'] = $stage;
        }

        if (!isset($context['status_code']) && null !== $e->getUpstreamStatus()) {
            $context['status_code'] = $e->getUpstreamStatus();
        }

        return new ProviderException(
            $e->getMessage(),
            $e->getProviderName(),
            $context,
            $e->getCode(),
            $e->getPrevious() ?? $e,
        );
    }

    /**
     * @return array{error_type: ?string, error_code: string|int|null, status_code: int, message?: string}
     */
    private function extractStructuredFields(\Throwable $e): array
    {
        // openai-php 0.22 throws this before it parses the JSON body, so the
        // HTTP status and the provider's error object would otherwise be lost.
        if ($e instanceof RateLimitException) {
            $parsed = $this->openAiErrorBody($e->response);

            return [
                'error_type' => $parsed['type'] ?? 'rate_limit_error',
                'error_code' => $parsed['code'],
                'status_code' => $e->response->getStatusCode(),
                'message' => $parsed['message'] ?? $e->getMessage(),
            ];
        }

        if ($e instanceof ErrorException) {
            return [
                'error_type' => $e->getErrorType(),
                'error_code' => $e->getErrorCode(),
                'status_code' => $e->getStatusCode(),
            ];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = 0;
            $errorType = null;
            $errorCode = null;

            try {
                $response = $e->getResponse();
                $status = $response->getStatusCode();
                $body = $this->decodeResponseBody($response);
                if (is_array($body)) {
                    $error = is_array($body['error'] ?? null) ? $body['error'] : $body;
                    $errorType = isset($error['type']) && is_string($error['type']) ? $error['type'] : null;
                    if (isset($error['code']) && (is_string($error['code']) || is_int($error['code']))) {
                        $errorCode = $error['code'];
                    }
                }
            } catch (\Throwable) {
                // Response body unavailable — keep what we have.
            }

            return [
                'error_type' => $errorType,
                'error_code' => $errorCode,
                'status_code' => $status,
            ];
        }

        $code = $e->getCode();

        return [
            'error_type' => null,
            'error_code' => null,
            'status_code' => $code >= 400 && $code <= 599 ? $code : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeResponseBody(ResponseInterface $response): ?array
    {
        try {
            return $response->toArray(false);
        } catch (\Throwable) {
            try {
                $raw = $response->getContent(false);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{message: ?string, type: ?string, code: string|int|null}
     */
    private function openAiErrorBody(PsrResponse $response): array
    {
        $empty = ['message' => null, 'type' => null, 'code' => null];

        try {
            $body = $response->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $decoded = json_decode($body->getContents(), true);
        } catch (\Throwable) {
            return $empty;
        }

        if (!is_array($decoded)) {
            return $empty;
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : $decoded;
        $message = $error['message'] ?? null;
        if (is_array($message)) {
            $message = implode("\n", array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $message));
        }
        $code = $error['code'] ?? null;

        return [
            'message' => is_string($message) && '' !== $message ? $message : null,
            'type' => isset($error['type']) && is_string($error['type']) ? $error['type'] : null,
            'code' => is_string($code) || is_int($code) ? $code : null,
        ];
    }
}

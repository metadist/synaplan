<?php

declare(strict_types=1);

namespace App\Service\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A refusal on the OpenAI-compatible gateway, rendered in the OpenAI error
 * shape (`{error: {message, type, param, code}}`) by {@see toResponse()}.
 */
final class OpenAiGatewayException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $type,
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message, string $errorCode): self
    {
        return new self($message, 'invalid_request_error', $errorCode, 400);
    }

    public static function modelNotFound(?string $model, string $capabilityLabel): self
    {
        return new self(
            null !== $model
                ? sprintf('The model `%s` does not exist or is not available.', $model)
                : sprintf('No model specified and no default %s model is configured.', $capabilityLabel),
            'invalid_request_error',
            'model_not_found',
            404,
        );
    }

    public static function rateLimited(): self
    {
        return new self('Rate limit exceeded', 'rate_limit_error', 'rate_limit_exceeded', 429);
    }

    public static function budgetExhausted(): self
    {
        return new self('The monthly usage budget of this account is used up.', 'insufficient_quota', 'insufficient_quota', 429);
    }

    public static function upstream(string $what): self
    {
        return new self(sprintf('The %s provider did not return a result. Try again later.', $what), 'server_error', 'upstream_error', 502);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $this->getMessage(),
                'type' => $this->type,
                'param' => null,
                'code' => $this->errorCode,
            ],
        ], $this->httpStatus);
    }
}

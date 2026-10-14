<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A live-voice start or callback the client can show.
 *
 * The payload is the JSON body. Budget failures use the same shape as a typed message.
 */
final class VoiceLiveRejectedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $payload,
    ) {
        $error = $payload['error'] ?? 'rejected';
        parent::__construct(is_string($error) ? $error : 'rejected');
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}

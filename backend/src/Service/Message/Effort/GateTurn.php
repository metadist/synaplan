<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

/**
 * One rated turn as the gate sees it, whichever door it came through:
 * the chat (a stored message) or the Anthropic-compatible gateway (a
 * request body). The idempotency key keeps a retried stream or a retried
 * POST from starting a second research for the same question.
 */
final readonly class GateTurn
{
    /**
     * @param list<array{role: string, text: string}> $turns earlier turns for the brief, newest last
     */
    public function __construct(
        public string $question,
        public EffortVerdict $verdict,
        public ?string $folder,
        public ?string $language,
        public array $turns,
        public ?int $chatId,
        public ?int $assistantId,
        public string $idempotencyKey,
    ) {
    }
}

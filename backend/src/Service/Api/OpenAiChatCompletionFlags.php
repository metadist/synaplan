<?php

declare(strict_types=1);

namespace App\Service\Api;

/**
 * Optional completion switches that are not part of the message contract.
 *
 * Kept off {@see OpenAiChatCompletionRequest}'s constructor so that class
 * stays within the ten-parameter size limit.
 */
final readonly class OpenAiChatCompletionFlags
{
    public function __construct(
        public ?string $reasoningEffort = null,
        public bool $incognito = false,
    ) {
    }
}

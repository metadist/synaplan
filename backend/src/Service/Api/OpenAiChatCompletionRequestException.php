<?php

declare(strict_types=1);

namespace App\Service\Api;

/**
 * Malformed OpenAI Chat Completions body (tools, tool_choice, messages).
 */
final class OpenAiChatCompletionRequestException extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }
}

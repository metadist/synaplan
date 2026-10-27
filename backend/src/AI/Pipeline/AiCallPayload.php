<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Typed input payload for an {@see AiCall} (`03_contracts.md` §1.7.2).
 */
final readonly class AiCallPayload
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<string>               $texts
     * @param array<string, mixed>       $passthroughBody
     */
    public function __construct(
        public array $messages = [],
        public array $texts = [],
        public ?string $prompt = null,
        public ?string $filePath = null,
        public ?string $operationId = null,
        public array $passthroughBody = [],
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function chat(array $messages): self
    {
        return new self(messages: $messages);
    }

    public static function embed(string $text): self
    {
        return new self(texts: [$text]);
    }

    /**
     * @param list<string> $texts
     */
    public static function embedBatch(array $texts): self
    {
        return new self(texts: $texts);
    }

    public static function vision(string $imagePath, string $prompt): self
    {
        return new self(prompt: $prompt, filePath: $imagePath);
    }

    public static function mediaPrompt(string $prompt): self
    {
        return new self(prompt: $prompt);
    }

    public static function videoOperation(string $operationId): self
    {
        return new self(operationId: $operationId);
    }

    public static function audioFile(string $audioPath): self
    {
        return new self(filePath: $audioPath);
    }

    public static function speechText(string $text): self
    {
        return new self(prompt: $text);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function passthrough(array $body): self
    {
        return new self(passthroughBody: $body);
    }
}

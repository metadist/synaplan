<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What one tool call of a run gave back: the text for the model, or the
 * approval it now waits for.
 */
final readonly class ChatToolResult
{
    private function __construct(
        public string $text,
        public ?int $approvalId,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, null);
    }

    public static function waitsFor(int $approvalId): self
    {
        if ($approvalId < 1) {
            throw new \InvalidArgumentException('An approval has an id.');
        }

        return new self('', $approvalId);
    }

    public function waits(): bool
    {
        return null !== $this->approvalId;
    }
}

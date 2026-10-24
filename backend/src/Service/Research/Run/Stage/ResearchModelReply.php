<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/** One model answer. A failed call carries no provider text for the person. */
final readonly class ResearchModelReply
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public bool $ok,
        public bool $called,
        public ?array $data,
        public string $text,
        public string $modelKey,
        public ?float $cost,
    ) {
    }

    public static function failed(bool $called = false): self
    {
        return new self(false, $called, null, '', '', null);
    }
}

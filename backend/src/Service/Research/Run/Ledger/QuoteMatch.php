<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class QuoteMatch
{
    public function __construct(
        public string $text,
        public string $passageId,
        public bool $verified,
    ) {
    }

    /**
     * @return array{text: string, passage: string, verified: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'passage' => $this->passageId,
            'verified' => $this->verified,
        ];
    }
}

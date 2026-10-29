<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\EffortVerdict;

final readonly class RouterEvalRow
{
    public function __construct(
        public string $id,
        public Effort $label,
        public ?string $why,
        public EffortVerdict $verdict,
    ) {
    }

    public function agrees(): bool
    {
        return $this->verdict->effort === $this->label;
    }

    public function overEscalated(): bool
    {
        return Effort::Answer === $this->label && Effort::Research === $this->verdict->effort;
    }

    public function underEscalated(): bool
    {
        return Effort::Research === $this->label && Effort::Research !== $this->verdict->effort;
    }

    public function reasonAgrees(): bool
    {
        return $this->agrees() && null !== $this->why && $this->why === $this->verdict->reason->value;
    }
}

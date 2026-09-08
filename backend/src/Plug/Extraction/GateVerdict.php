<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

/**
 * Quality-gate outcome for one extraction attempt. Stored in meta.gate.
 */
final readonly class GateVerdict
{
    public function __construct(
        public bool $passed,
        public string $reason,
    ) {
    }

    public static function pass(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * @return array{passed: bool, reason: string}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'reason' => $this->reason,
        ];
    }
}

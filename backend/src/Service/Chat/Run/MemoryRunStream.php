<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

final class MemoryRunStream implements RunStream
{
    /** @var array<string, list<RunFrame>> */
    private array $runs = [];

    public function append(string $runId, int $seq, string $kind, string $frame): void
    {
        $this->runs[$runId][] = new RunFrame($seq, $kind, $frame);
    }

    public function after(string $runId, int $afterSeq): array
    {
        $frames = [];
        foreach ($this->runs[$runId] ?? [] as $frame) {
            if ($frame->seq > $afterSeq) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }
}

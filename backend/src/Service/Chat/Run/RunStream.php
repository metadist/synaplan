<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

interface RunStream
{
    public function append(string $runId, int $seq, string $kind, string $frame): void;

    /**
     * Frames whose seq is greater than $afterSeq.
     *
     * @return list<RunFrame>
     */
    public function after(string $runId, int $afterSeq): array;
}

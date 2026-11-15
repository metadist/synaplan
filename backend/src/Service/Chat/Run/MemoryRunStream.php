<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

final class MemoryRunStream implements RunStream
{
    /** @var array<string, list<RunFrame>> */
    private array $runs = [];

    /** @var array<string, int> */
    private array $bytes = [];

    /** @var array<string, bool> */
    private array $gapped = [];

    public function __construct(
        private readonly int $maxBytes = StreamPumpConfig::DEFAULT_MAX_BYTES,
    ) {
    }

    public function append(string $runId, int $seq, string $kind, string $frame): void
    {
        $decision = StreamByteBudget::decide(
            $this->bytes[$runId] ?? 0,
            strlen($frame),
            $this->maxBytes,
            $this->gapped[$runId] ?? false,
        );
        if (StreamByteBudget::DROP === $decision) {
            return;
        }
        if (StreamByteBudget::GAP === $decision) {
            $this->gapped[$runId] = true;
            $this->runs[$runId][] = new RunFrame($seq, 'gap', PumpFrames::LOG_CAPPED);

            return;
        }
        $this->bytes[$runId] = ($this->bytes[$runId] ?? 0) + strlen($frame);
        $this->runs[$runId][] = new RunFrame($seq, $kind, $frame);
    }

    public function after(string $runId, int $afterSeq, int $blockMs = 0): array
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

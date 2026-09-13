<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeRunStatus
{
    private const KEYS = ['runId', 'status', 'exitCode', 'reason', 'startedAt', 'finishedAt', 'durationMs', 'usage', 'truncated'];

    /**
     * @param array{wallMs: int, cpuSec: float, maxMemoryMb: int, bytesIn: int, bytesOut: int} $usage
     * @param array{stdout: bool, stderr: bool}                                                $truncated
     */
    public function __construct(
        public string $runId,
        public string $status,
        public array $usage,
        public array $truncated,
        public ?int $exitCode = null,
        public ?string $reason = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?int $durationMs = null,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, self::KEYS);
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        ComputeJson::assertKeys($usage, ['wallMs', 'cpuSec', 'maxMemoryMb', 'bytesIn', 'bytesOut']);
        $truncated = is_array($data['truncated'] ?? null) ? $data['truncated'] : [];
        ComputeJson::assertKeys($truncated, ['stdout', 'stderr']);

        return new self(
            runId: (string) ($data['runId'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            usage: [
                'wallMs' => (int) ($usage['wallMs'] ?? 0),
                'cpuSec' => (float) ($usage['cpuSec'] ?? 0),
                'maxMemoryMb' => (int) ($usage['maxMemoryMb'] ?? 0),
                'bytesIn' => (int) ($usage['bytesIn'] ?? 0),
                'bytesOut' => (int) ($usage['bytesOut'] ?? 0),
            ],
            truncated: [
                'stdout' => (bool) ($truncated['stdout'] ?? false),
                'stderr' => (bool) ($truncated['stderr'] ?? false),
            ],
            exitCode: isset($data['exitCode']) ? (int) $data['exitCode'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            startedAt: isset($data['startedAt']) ? (string) $data['startedAt'] : null,
            finishedAt: isset($data['finishedAt']) ? (string) $data['finishedAt'] : null,
            durationMs: isset($data['durationMs']) ? (int) $data['durationMs'] : null,
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled'], true);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeHealth
{
    private const KEYS = ['protocol', 'tier', 'images', 'capacity', 'caps', 'features'];

    /**
     * @param list<array{key: string, ref: string}>                                       $images
     * @param array{maxConcurrent: int, running: int, queued: int}                        $capacity
     * @param array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int} $caps
     * @param array{workspaces: bool, egress: bool}                                       $features
     */
    public function __construct(
        public int $protocol,
        public string $tier,
        public array $images,
        public array $capacity,
        public array $caps,
        public array $features,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, self::KEYS);
        $images = [];
        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            ComputeJson::assertKeys($image, ['key', 'ref']);
            $images[] = ['key' => (string) ($image['key'] ?? ''), 'ref' => (string) ($image['ref'] ?? '')];
        }
        $capacity = is_array($data['capacity'] ?? null) ? $data['capacity'] : [];
        ComputeJson::assertKeys($capacity, ['maxConcurrent', 'running', 'queued']);
        $caps = is_array($data['caps'] ?? null) ? $data['caps'] : [];
        ComputeJson::assertKeys($caps, ['timeoutSec', 'memoryMb', 'cpu', 'pids', 'outputMb']);
        $features = is_array($data['features'] ?? null) ? $data['features'] : [];
        ComputeJson::assertKeys($features, ['workspaces', 'egress']);

        return new self(
            protocol: (int) ($data['protocol'] ?? 0),
            tier: (string) ($data['tier'] ?? ''),
            images: $images,
            capacity: [
                'maxConcurrent' => (int) ($capacity['maxConcurrent'] ?? 0),
                'running' => (int) ($capacity['running'] ?? 0),
                'queued' => (int) ($capacity['queued'] ?? 0),
            ],
            caps: [
                'timeoutSec' => (int) ($caps['timeoutSec'] ?? 0),
                'memoryMb' => (int) ($caps['memoryMb'] ?? 0),
                'cpu' => (float) ($caps['cpu'] ?? 0),
                'pids' => (int) ($caps['pids'] ?? 0),
                'outputMb' => (int) ($caps['outputMb'] ?? 0),
            ],
            features: [
                'workspaces' => (bool) ($features['workspaces'] ?? false),
                'egress' => (bool) ($features['egress'] ?? false),
            ],
        );
    }
}

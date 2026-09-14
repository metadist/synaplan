<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeWorkspaceUsage
{
    public function __construct(
        public int $usedMb,
        public int $quotaMb,
        public int $fileCount,
        public string $lastUsedAt,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, ['usedMb', 'quotaMb', 'fileCount', 'lastUsedAt']);

        return new self(
            (int) ($data['usedMb'] ?? 0),
            (int) ($data['quotaMb'] ?? 0),
            (int) ($data['fileCount'] ?? 0),
            (string) ($data['lastUsedAt'] ?? ''),
        );
    }
}

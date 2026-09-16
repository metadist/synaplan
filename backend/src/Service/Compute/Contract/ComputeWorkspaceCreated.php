<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeWorkspaceCreated
{
    public function __construct(
        public string $workspaceId,
        public int $quotaMb,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, ['workspaceId', 'quotaMb']);

        return new self((string) ($data['workspaceId'] ?? ''), (int) ($data['quotaMb'] ?? 0));
    }
}

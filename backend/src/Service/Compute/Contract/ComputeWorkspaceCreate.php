<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeWorkspaceCreate
{
    public function __construct(
        public string $owner,
        public int $quotaMb,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, ['owner', 'quotaMb']);

        return new self((string) ($data['owner'] ?? ''), (int) ($data['quotaMb'] ?? 0));
    }
}

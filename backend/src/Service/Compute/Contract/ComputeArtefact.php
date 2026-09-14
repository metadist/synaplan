<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeArtefact
{
    private const KEYS = ['name', 'size', 'mime', 'sha256', 'rejected'];

    public function __construct(
        public string $name,
        public int $size,
        public string $mime,
        public ?string $sha256 = null,
        public ?string $rejected = null,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function listFromJson(string $json): array
    {
        $rows = ComputeJson::decodeObjectList($json, self::KEYS);
        $out = [];
        foreach ($rows as $row) {
            $out[] = new self(
                name: (string) ($row['name'] ?? ''),
                size: (int) ($row['size'] ?? 0),
                mime: (string) ($row['mime'] ?? ''),
                sha256: isset($row['sha256']) ? (string) $row['sha256'] : null,
                rejected: isset($row['rejected']) ? (string) $row['rejected'] : null,
            );
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

final readonly class ComputeWorkspaceFile
{
    public function __construct(
        public string $path,
        public int $size,
        public string $mime,
        public string $modifiedAt,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function listFromJson(string $json): array
    {
        $rows = ComputeJson::decodeObjectList($json, ['path', 'size', 'mime', 'modifiedAt']);
        $out = [];
        foreach ($rows as $row) {
            $out[] = new self(
                (string) ($row['path'] ?? ''),
                (int) ($row['size'] ?? 0),
                (string) ($row['mime'] ?? ''),
                (string) ($row['modifiedAt'] ?? ''),
            );
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Scores a report the desktop runner already wrote. This arm does not call a model.
 *
 * @phpstan-type ImportedReport array{id: string, text: string}
 */
final readonly class B1Importer
{
    /**
     * @return array<string, string> item id => report text
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Import file not found: '.$path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Import file is not valid JSON.', 0, $e);
        }
        $rows = \is_array($decoded) ? ($decoded['items'] ?? null) : null;
        if (!\is_array($rows)) {
            throw new \RuntimeException('Import file needs an items list of id and text.');
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['text'] ?? null)) {
                throw new \RuntimeException('Each imported item needs an id and text.');
            }
            $out[$row['id']] = $row['text'];
        }

        return $out;
    }
}

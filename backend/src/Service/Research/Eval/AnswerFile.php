<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Saved answers of one arm, so citations can be judged after the run.
 * Evidence maps an evidence id to the passage the report cites; an arm that
 * cites URLs leaves it empty.
 *
 * @phpstan-type SavedAnswer array{id: string, arm: string, run: int, text: string, evidence: array<string, array{url: string, passage: string}>}
 */
final class AnswerFile
{
    /**
     * @param list<SavedAnswer> $answers
     */
    public function write(string $path, array $answers): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the answers directory '.$dir.'.');
        }
        $json = json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (false === file_put_contents($path, $json."\n")) {
            throw new \RuntimeException('Could not write the answers to '.$path.'.');
        }
    }

    /**
     * @return list<SavedAnswer>
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The answers file '.$path.' does not exist.');
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException('The answers file '.$path.' is not a JSON list.');
        }
        $out = [];
        foreach ($rows as $index => $row) {
            if (!\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['text'] ?? null)) {
                throw new \RuntimeException(sprintf('Answer %d in %s has no id or text.', $index, $path));
            }
            $out[] = [
                'id' => $row['id'],
                'arm' => \is_string($row['arm'] ?? null) ? $row['arm'] : 'unknown',
                'run' => \is_int($row['run'] ?? null) ? $row['run'] : 1,
                'text' => $row['text'],
                'evidence' => $this->evidence($row['evidence'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{url: string, passage: string}>
     */
    private function evidence(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $id => $row) {
            if (\is_string($id) && \is_array($row) && \is_string($row['passage'] ?? null)) {
                $out[$id] = ['url' => \is_string($row['url'] ?? null) ? $row['url'] : '', 'passage' => $row['passage']];
            }
        }

        return $out;
    }
}

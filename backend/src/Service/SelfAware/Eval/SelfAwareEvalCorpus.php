<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Eval;

/**
 * Loader for {@see backend/tests/Eval/self_aware_eval_corpus.json}.
 *
 * @phpstan-type EvalRow array{
 *     id: string,
 *     lang: string,
 *     text: string,
 *     install: string,
 *     expect: array<string, mixed>
 * }
 */
final class SelfAwareEvalCorpus
{
    /**
     * @return list<EvalRow>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Eval corpus not found: '.$path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Eval corpus is not valid JSON: '.$e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Eval corpus must be a JSON array.');
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['id'], $row['text'], $row['expect']) || !is_array($row['expect'])) {
                throw new \InvalidArgumentException('Eval corpus row is missing id, text or expect.');
            }
            $lang = isset($row['lang']) && is_string($row['lang']) && '' !== $row['lang'] ? $row['lang'] : 'en';
            $install = isset($row['install']) && is_string($row['install']) && '' !== $row['install']
                ? $row['install']
                : 'any';
            $rows[] = [
                'id' => (string) $row['id'],
                'lang' => $lang,
                'text' => (string) $row['text'],
                'install' => $install,
                'expect' => $row['expect'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<EvalRow> $rows
     *
     * @return list<EvalRow>
     */
    public static function select(array $rows, ?string $only, ?string $install): array
    {
        $wanted = [];
        if (null !== $only && '' !== trim($only)) {
            foreach (explode(',', $only) as $id) {
                $id = trim($id);
                if ('' !== $id) {
                    $wanted[$id] = true;
                }
            }
        }

        $filtered = [];
        foreach ($rows as $row) {
            if ([] !== $wanted && !isset($wanted[(string) $row['id']])) {
                continue;
            }
            if (null !== $install && '' !== $install && $row['install'] !== $install && 'any' !== $row['install']) {
                continue;
            }
            $filtered[] = $row;
        }

        return $filtered;
    }
}

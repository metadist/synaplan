<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Reads `corpus.json`. A research turn names an item of the research corpus
 * (roadmap row 21) and takes its question and gold facts from there, so both
 * corpora score the same words.
 */
final class ChatLoopCorpus
{
    public const GROUPS = ['simple', 'search', 'files', 'multi_step', 'research'];
    public const LANGUAGES = ['en', 'de', 'es', 'fr', 'tr'];

    private const RESEARCH_DIR = __DIR__.'/../research';
    private const FIXTURE_DIR = __DIR__.'/fixtures';

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $researchFiles = [];

    /**
     * @return list<CorpusTurn>
     */
    public function load(string $path = __DIR__.'/corpus.json'): array
    {
        $rows = self::decode($path)['turns'] ?? null;
        if (!\is_array($rows) || !array_is_list($rows) || [] === $rows) {
            throw new \RuntimeException('The corpus '.$path.' has no list "turns".');
        }

        $turns = [];
        foreach ($rows as $index => $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException(sprintf('Turn %d in %s is not an object.', $index, $path));
            }
            $turn = $this->turn($row, $index);
            if (isset($turns[$turn->id])) {
                throw new \RuntimeException('The turn id "'.$turn->id.'" is used twice.');
            }
            $turns[$turn->id] = $turn;
        }

        return array_values($turns);
    }

    public static function fixturePath(string $name): string
    {
        if ($name !== basename($name) || !is_file(self::FIXTURE_DIR.'/'.$name)) {
            throw new \RuntimeException('The fixture "'.$name.'" does not exist in '.self::FIXTURE_DIR.'.');
        }

        return self::FIXTURE_DIR.'/'.$name;
    }

    /**
     * @param array<mixed> $row
     */
    private function turn(array $row, int $index): CorpusTurn
    {
        $id = self::text($row, 'id', $index);
        $group = self::text($row, 'group', $index);
        if (!\in_array($group, self::GROUPS, true)) {
            throw new \RuntimeException(sprintf('Turn "%s" has the unknown group "%s".', $id, $group));
        }

        $text = $row['text'] ?? null;
        $language = $row['language'] ?? null;
        $expect = $row['expect'] ?? [];
        if (\is_string($row['researchItem'] ?? null)) {
            $item = $this->researchItem($row['researchItem'], $id);
            $text = $item['question'] ?? null;
            $language = $item['language'] ?? null;
            $expect = array_map(static fn (mixed $gold): mixed => \is_array($gold) ? ($gold['match'] ?? []) : [], \is_array($item['gold'] ?? null) ? $item['gold'] : []);
        }
        if (!\is_string($text) || '' === trim($text)) {
            throw new \RuntimeException('Turn "'.$id.'" has no text.');
        }
        if (!\is_string($language) || !\in_array($language, self::LANGUAGES, true)) {
            throw new \RuntimeException('Turn "'.$id.'" has no language of '.implode(', ', self::LANGUAGES).'.');
        }

        $files = self::strings($row['files'] ?? [], $id, 'files');
        foreach ($files as $file) {
            self::fixturePath($file);
        }

        return new CorpusTurn($id, $group, $language, $text, true === ($row['webSearch'] ?? false), $files, self::assertions($expect, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function researchItem(string $reference, string $turnId): array
    {
        [$file, $itemId] = array_pad(explode('#', $reference, 2), 2, '');
        if ($file !== basename($file) || '' === $itemId) {
            throw new \RuntimeException('Turn "'.$turnId.'" names the research item "'.$reference.'"; use file.json#id.');
        }
        if (!isset($this->researchFiles[$file])) {
            $this->researchFiles[$file] = [];
            foreach (self::decode(self::RESEARCH_DIR.'/'.$file) as $item) {
                if (\is_array($item) && \is_string($item['id'] ?? null)) {
                    $this->researchFiles[$file][$item['id']] = $item;
                }
            }
        }

        return $this->researchFiles[$file][$itemId]
            ?? throw new \RuntimeException('Turn "'.$turnId.'" names the research item "'.$reference.'", which does not exist.');
    }

    /**
     * @return list<list<string>>
     */
    private static function assertions(mixed $expect, string $id): array
    {
        if (!\is_array($expect)) {
            throw new \RuntimeException('Turn "'.$id.'": "expect" is not a list.');
        }
        $out = [];
        foreach ($expect as $assertion) {
            $strings = self::strings($assertion, $id, 'expect');
            if ([] !== $strings) {
                $out[] = $strings;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $id, string $field): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException(sprintf('Turn "%s": "%s" is not a list.', $id, $field));
        }
        $out = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw new \RuntimeException(sprintf('Turn "%s": "%s" holds something that is not text.', $id, $field));
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<mixed> $row
     */
    private static function text(array $row, string $field, int $index): string
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \RuntimeException(sprintf('Turn %d has no "%s".', $index, $field));
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The file '.$path.' does not exist.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('The file '.$path.' is not valid JSON.');
        }

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\Service\Research\Run\ResearchFollowUp;

/**
 * Reads the research corpus. A bad row fails the load; it is never skipped.
 */
final readonly class CorpusLoader
{
    public const CATEGORIES = [
        'current',
        'comparison',
        'regulation',
        'technical',
        'science',
        'local',
        'documents',
        'mixed',
        'trap',
    ];

    /**
     * @return list<CorpusItem>
     */
    public function load(string $path, bool $smokeOnly): array
    {
        $items = [];
        foreach ($this->split($path)['questions'] as $item) {
            if ($smokeOnly && !$item->smoke) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Follow-ups are scored on a finished parent, not as fresh questions.
     *
     * @return list<FollowUpCorpusRow>
     */
    public function followUps(string $path): array
    {
        return $this->split($path)['followUps'];
    }

    /**
     * @return array{questions: list<CorpusItem>, followUps: list<FollowUpCorpusRow>}
     */
    private function split(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Corpus file not found: '.$path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Corpus file is not valid JSON.', 0, $e);
        }
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Corpus file is not a JSON array.');
        }

        $questions = [];
        $followUps = [];
        $seen = [];
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException('A corpus row is not an object.');
            }
            $item = $this->item($row);
            if (isset($seen[$item->id])) {
                throw new \RuntimeException('Duplicate corpus id: '.$item->id);
            }
            $seen[$item->id] = true;
            $kind = $this->followUp($item->id, $row);
            if (null === $kind) {
                $questions[] = $item;
                continue;
            }
            $parent = $row['parent'] ?? null;
            if (!\is_string($parent) || '' === $parent) {
                throw new \RuntimeException($item->id.' is a follow-up without a parent.');
            }
            $followUps[] = new FollowUpCorpusRow($item->id, $kind, $item->question, $parent, $item->facts);
        }
        $parents = array_map(static fn (CorpusItem $item): string => $item->id, $questions);
        foreach ($followUps as $followUp) {
            if (!\in_array($followUp->parent, $parents, true)) {
                throw new \RuntimeException($followUp->id.' continues '.$followUp->parent.', which is not a question in this corpus.');
            }
        }

        return ['questions' => $questions, 'followUps' => $followUps];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function item(array $row): CorpusItem
    {
        foreach (['id', 'category', 'language', 'question', 'depth', 'sources', 'gold', 'smoke'] as $key) {
            if (!\array_key_exists($key, $row)) {
                throw new \RuntimeException('A corpus item is missing '.$key.'.');
            }
        }

        $id = $this->string($row, 'id');
        $category = $this->string($row, 'category');
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new \RuntimeException($id.' has an unknown category.');
        }

        $sources = $row['sources'];
        if (!\is_array($sources) || !\is_bool($sources['web'] ?? null) || !\is_bool($sources['knowledge'] ?? null)) {
            throw new \RuntimeException($id.' needs sources.web and sources.knowledge as true or false.');
        }

        return new CorpusItem(
            $id,
            $category,
            $this->string($row, 'language'),
            $this->string($row, 'question'),
            $this->string($row, 'depth'),
            $sources['web'],
            $sources['knowledge'],
            new CorpusFacts(
                $this->gold($id, $row['gold']),
                $this->stringList($id, $row['preferred_sources'] ?? [], 'preferred_sources'),
                $this->forbidden($id, $row['trap'] ?? null),
            ),
            $this->smoke($id, $row['smoke']),
            isset($row['valid_until']) && \is_string($row['valid_until']) && '' !== $row['valid_until'] ? $row['valid_until'] : null,
        );
    }

    /**
     * @return list<array{id: string, assertion: string, kind: string, match: list<string>}>
     */
    private function gold(string $id, mixed $gold): array
    {
        if (!\is_array($gold)) {
            throw new \RuntimeException($id.' gold must be a list.');
        }

        $out = [];
        foreach ($gold as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || !\is_string($entry['assertion'] ?? null)) {
                throw new \RuntimeException($id.' has a gold row without an id and an assertion.');
            }
            $match = $entry['match'] ?? [];
            if (!\is_array($match)) {
                throw new \RuntimeException($id.' match must be a list of strings.');
            }
            $needles = [];
            foreach ($match as $needle) {
                if (!\is_string($needle) || '' === $needle) {
                    throw new \RuntimeException($id.' has an empty match string.');
                }
                $needles[] = $needle;
            }
            $out[] = [
                'id' => $entry['id'],
                'assertion' => $entry['assertion'],
                'kind' => \is_string($entry['kind'] ?? null) && '' !== $entry['kind'] ? $entry['kind'] : 'fact',
                'match' => $needles,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function forbidden(string $id, mixed $trap): array
    {
        if (null === $trap) {
            return [];
        }
        if (!\is_array($trap) || !isset($trap['forbidden']) || !\is_array($trap['forbidden'])) {
            throw new \RuntimeException($id.' trap needs a forbidden list.');
        }

        return $this->stringList($id, $trap['forbidden'], 'trap.forbidden');
    }

    private function smoke(string $id, mixed $value): bool
    {
        if (!\is_bool($value)) {
            throw new \RuntimeException($id.' smoke must be true or false.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function followUp(string $id, array $row): ?string
    {
        if (!\array_key_exists('follow_up', $row) || null === $row['follow_up']) {
            return null;
        }
        $kind = $row['follow_up'];
        if (!\is_string($kind) || !\in_array($kind, ResearchFollowUp::KINDS, true)) {
            throw new \RuntimeException($id.' has an unknown follow-up.');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \RuntimeException('A corpus item has an empty '.$key.'.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $id, mixed $value, string $label): array
    {
        if (!\is_array($value)) {
            throw new \RuntimeException($id.' '.$label.' must be a list of strings.');
        }
        $out = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                throw new \RuntimeException($id.' '.$label.' has an empty entry.');
            }
            $out[] = $entry;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\PlainText;

/**
 * One question the person can answer or skip. Skip means the run continues
 * with its own judgement.
 */
final class ResearchQuestion
{
    private const TEXT = 300;

    private const OPTION = 120;

    /**
     * @param list<string> $options
     */
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly array $options,
        public readonly bool $allowText,
        public ?string $answer = null,
        public bool $skipped = false,
    ) {
    }

    public function open(): bool
    {
        return !$this->skipped && null === $this->answer;
    }

    /**
     * @return array{id: string, text: string, options: list<string>, allow_text: bool}
     */
    public function event(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'options' => $this->options,
            'allow_text' => $this->allowText,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'options' => $this->options,
            'allow_text' => $this->allowText,
            'answer' => $this->answer,
            'skipped' => $this->skipped,
        ];
    }

    /**
     * Questions the brief listed, up to the budget still left. A row without
     * a question and two choices is dropped.
     *
     * @return list<self>
     */
    public static function collect(mixed $rows, int $cap): array
    {
        if ($cap < 1 || !is_array($rows)) {
            return [];
        }
        $kept = [];
        $used = [];
        $n = 1;
        foreach ($rows as $row) {
            if (count($kept) >= $cap || !is_array($row)) {
                continue;
            }
            $one = self::one($row, $n, $used);
            if (null === $one) {
                continue;
            }
            $used[$one->id] = true;
            $kept[] = $one;
            ++$n;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function fromTool(array $arguments): ?self
    {
        return self::one([
            'text' => $arguments['question'] ?? '',
            'options' => $arguments['options'] ?? [],
            'allow_text' => false,
        ], 1, []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $text = PlainText::line((string) ($data['text'] ?? ''), self::TEXT);
        $options = self::options($data['options'] ?? null);
        $id = (string) ($data['id'] ?? '');
        if ('' === $text || null === $options || 1 !== preg_match('/^q[1-9][0-9]*$/', $id)) {
            return null;
        }
        $answer = $data['answer'] ?? null;

        return new self(
            $id,
            $text,
            $options,
            true === ($data['allow_text'] ?? false),
            is_string($answer) && '' !== $answer ? PlainText::line($answer, self::TEXT) : null,
            true === ($data['skipped'] ?? false),
        );
    }

    /**
     * @param array<mixed>        $row
     * @param array<string, true> $used
     */
    private static function one(array $row, int $n, array $used): ?self
    {
        $text = PlainText::line((string) ($row['text'] ?? ''), self::TEXT);
        $options = self::options($row['options'] ?? null);
        if ('' === $text || null === $options) {
            return null;
        }
        $id = (string) ($row['id'] ?? '');
        if (1 !== preg_match('/^q[1-9][0-9]*$/', $id) || isset($used[$id])) {
            $id = 'q'.$n;
            while (isset($used[$id])) {
                ++$n;
                $id = 'q'.$n;
            }
        }

        return new self($id, $text, $options, true === ($row['allow_text'] ?? false));
    }

    /**
     * @return list<string>|null
     */
    private static function options(mixed $rows): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        $options = [];
        foreach ($rows as $row) {
            if (!is_string($row) || count($options) >= 4) {
                continue;
            }
            $option = PlainText::line($row, self::OPTION);
            if ('' !== $option && !in_array($option, $options, true)) {
                $options[] = $option;
            }
        }

        return count($options) >= 2 ? $options : null;
    }
}

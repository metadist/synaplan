<?php

declare(strict_types=1);

namespace App\Service\Context;

/**
 * Result of fitting a text into a budget. `strategy` tells the caller (and
 * the model, via a short provenance note) what happened:
 *
 *   - verbatim   — fit as-is, nothing changed
 *   - condensed  — one or more question-aware map-reduce rounds
 *   - trimmed    — hard head/tail cut (condensation disabled, exhausted or failed)
 */
final readonly class CondensedText
{
    public const STRATEGY_VERBATIM = 'verbatim';
    public const STRATEGY_CONDENSED = 'condensed';
    public const STRATEGY_TRIMMED = 'trimmed';

    /**
     * @param list<int> $chunksPerLevel number of chunks condensed at each level
     */
    public function __construct(
        public string $text,
        public string $strategy,
        public int $originalChars,
        public int $budgetChars,
        public int $levels = 0,
        public array $chunksPerLevel = [],
        public bool $trimmedAfterCondense = false,
        public int $modelCalls = 0,
    ) {
    }

    public static function verbatim(string $text, int $budgetChars): self
    {
        $length = mb_strlen($text);

        return new self($text, self::STRATEGY_VERBATIM, $length, $budgetChars);
    }

    public function isVerbatim(): bool
    {
        return self::STRATEGY_VERBATIM === $this->strategy;
    }

    public function finalChars(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * One-line provenance the caller can put in front of the text so the
     * answering model knows it is looking at a condensed view and phrases
     * limits honestly instead of inventing "the file only contains …".
     */
    public function provenanceNote(): ?string
    {
        return match ($this->strategy) {
            self::STRATEGY_CONDENSED => sprintf(
                '[Note: the original content (%s characters) was too large for the model window and was condensed in %d round(s) with focus on the user\'s question. Exact figures quoted below come from the source; state clearly when a detail is not present in this condensed view.]',
                number_format($this->originalChars),
                $this->levels,
            ),
            self::STRATEGY_TRIMMED => sprintf(
                '[Note: only the beginning and end of the original content (%s characters) are included; the middle part was cut to fit the model window. Say so when the answer depends on the omitted part.]',
                number_format($this->originalChars),
            ),
            default => null,
        };
    }

    /**
     * @return array<string, int|string|bool|list<int>>
     */
    public function toLogContext(): array
    {
        return [
            'strategy' => $this->strategy,
            'original_chars' => $this->originalChars,
            'final_chars' => $this->finalChars(),
            'budget_chars' => $this->budgetChars,
            'levels' => $this->levels,
            'chunks_per_level' => $this->chunksPerLevel,
            'model_calls' => $this->modelCalls,
            'trimmed_after_condense' => $this->trimmedAfterCondense,
        ];
    }
}

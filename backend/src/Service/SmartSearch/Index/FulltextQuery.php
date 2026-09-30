<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * Turns free user input into a safe InnoDB boolean-mode FULLTEXT expression.
 *
 * Every word becomes a prefix term (`word*`). A short query requires all of
 * its words, like any keyword search, and relaxes to optional words when that
 * finds too little; a longer sentence keeps them optional and ranks by how
 * many match — the meaning tier covers the fuzzy side.
 * Function words (see {@see StopWords}) and typed operators are dropped.
 * Words shorter than InnoDB's minimum token size can never match FULLTEXT,
 * so a query without long words falls back to a title LIKE.
 */
final readonly class FulltextQuery
{
    /** InnoDB `innodb_ft_min_token_size` default. */
    public const MIN_TOKEN_LENGTH = 3;
    private const MAX_TERMS = 8;
    /** Up to this many words, every word must match. */
    private const MAX_REQUIRED_TERMS = 3;

    /** @var list<string> */
    public array $terms;

    public function __construct(public string $raw)
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) >= self::MIN_TOKEN_LENGTH && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }
        $content = array_values(array_filter($terms, static fn (string $term): bool => !StopWords::contains($term)));
        // A query of only function words ("the who") falls back to the
        // title LIKE on the raw text instead of matching every body.
        $this->terms = array_slice($content, 0, self::MAX_TERMS);
    }

    public function hasTerms(): bool
    {
        return [] !== $this->terms;
    }

    public function booleanExpression(): string
    {
        return $this->expression($this->requiresAll() ? '+' : '');
    }

    /**
     * Every word optional — the fallback when requiring all words finds too
     * little. Null when the main expression is already optional.
     */
    public function relaxedExpression(): ?string
    {
        return $this->requiresAll() && count($this->terms) > 1 ? $this->expression('') : null;
    }

    private function requiresAll(): bool
    {
        return count($this->terms) <= self::MAX_REQUIRED_TERMS;
    }

    private function expression(string $prefix): string
    {
        return implode(' ', array_map(static fn (string $term): string => $prefix.$term.'*', $this->terms));
    }

    public function likePattern(): string
    {
        return '%'.addcslashes(trim($this->raw), '%_\\').'%';
    }
}

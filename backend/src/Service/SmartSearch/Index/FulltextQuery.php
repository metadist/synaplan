<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * Turns free user input into a safe InnoDB boolean-mode FULLTEXT expression.
 *
 * Every word becomes an optional prefix term (`word*`), so results rank by how
 * many words match instead of requiring all of them. Operators typed by the
 * user are stripped. Words shorter than InnoDB's minimum token size can never
 * match FULLTEXT, so a query without long words falls back to a title LIKE.
 */
final readonly class FulltextQuery
{
    /** InnoDB `innodb_ft_min_token_size` default. */
    public const MIN_TOKEN_LENGTH = 3;
    private const MAX_TERMS = 8;

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
        $this->terms = array_slice($terms, 0, self::MAX_TERMS);
    }

    public function hasTerms(): bool
    {
        return [] !== $this->terms;
    }

    public function booleanExpression(): string
    {
        return implode(' ', array_map(static fn (string $term): string => $term.'*', $this->terms));
    }

    public function likePattern(): string
    {
        return '%'.addcslashes(trim($this->raw), '%_\\').'%';
    }
}

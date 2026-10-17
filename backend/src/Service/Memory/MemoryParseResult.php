<?php

declare(strict_types=1);

namespace App\Service\Memory;

/**
 * What parsing a memory input came to: the proposed actions with the similar
 * memories they were weighed against, or why there are none — no memory
 * model, an answer without actions, or an unreachable provider. What the
 * model answered and why the search failed travel along for an admin.
 */
final readonly class MemoryParseResult
{
    public const PARSED = 'parsed';
    public const NO_MODEL = 'no_model';
    public const NOT_UNDERSTOOD = 'not_understood';
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<mixed>               $similarMemories
     */
    private function __construct(
        public string $outcome,
        public array $actions = [],
        public array $similarMemories = [],
        public ?string $searchError = null,
        public string $answer = '',
        public string $providerError = '',
    ) {
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<mixed>               $similarMemories
     */
    public static function parsed(array $actions, array $similarMemories, ?string $searchError, string $answer): self
    {
        return new self(self::PARSED, $actions, $similarMemories, $searchError, $answer);
    }

    public static function noModel(): self
    {
        return new self(self::NO_MODEL);
    }

    public static function notUnderstood(string $answer): self
    {
        return new self(self::NOT_UNDERSTOOD, answer: $answer);
    }

    public static function providerUnavailable(string $error): self
    {
        return new self(self::PROVIDER_UNAVAILABLE, providerError: $error);
    }
}

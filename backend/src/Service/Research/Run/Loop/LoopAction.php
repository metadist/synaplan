<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Loop;

/**
 * One action the engine will run. Invalid and over-budget actions never
 * become one of these.
 */
final readonly class LoopAction
{
    public const SEARCH = 'search';

    public const READ = 'read';

    public const REREAD = 'reread';

    public const ASK_USER = 'ask_user';

    /**
     * @param list<string> $options
     */
    private function __construct(
        public string $type,
        public ?string $point = null,
        public ?string $query = null,
        public ?string $language = null,
        public ?string $scope = null,
        public ?string $source = null,
        public ?string $question = null,
        public array $options = [],
    ) {
    }

    public static function search(string $point, string $query, ?string $language, string $scope): self
    {
        return new self(self::SEARCH, point: $point, query: $query, language: $language, scope: $scope);
    }

    public static function read(string $source): self
    {
        return new self(self::READ, source: $source);
    }

    public static function reread(string $source, string $question): self
    {
        return new self(self::REREAD, source: $source, question: $question);
    }

    /**
     * @param list<string> $options
     */
    public static function askUser(string $question, array $options): self
    {
        return new self(self::ASK_USER, question: $question, options: $options);
    }
}

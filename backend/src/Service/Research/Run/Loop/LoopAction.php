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
    public function __construct(
        public string $type,
        public ?string $point,
        public ?string $query,
        public ?string $language,
        public ?string $scope,
        public ?string $source,
        public ?string $question,
        public array $options = [],
    ) {
    }
}

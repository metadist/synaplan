<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * What one run found in one place that stores secrets.
 */
final readonly class SecretReencryptTally
{
    public const CURRENT = 'current';
    public const REWRITTEN = 'rewritten';
    public const UNREADABLE = 'unreadable';
    public const CHANGED = 'changed';

    /**
     * @param int       $current       already in the current format and bound to its place
     * @param int       $rewritten     written anew, or due to be in a trial run
     * @param list<int> $unreadableIds rows this installation's APP_SECRET does not open; left as they are
     * @param int       $changed       saved by their owner during the run, so already current
     */
    public function __construct(
        public string $location,
        public int $current,
        public int $rewritten,
        public array $unreadableIds,
        public int $changed,
    ) {
    }

    /**
     * @param list<array{id: int, outcome: string}> $outcomes
     */
    public static function of(string $location, array $outcomes): self
    {
        $count = static fn (string $outcome): int => count(array_filter(
            $outcomes,
            static fn (array $row): bool => $outcome === $row['outcome'],
        ));

        return new self(
            $location,
            $count(self::CURRENT),
            $count(self::REWRITTEN),
            array_values(array_map(
                static fn (array $row): int => $row['id'],
                array_filter($outcomes, static fn (array $row): bool => self::UNREADABLE === $row['outcome']),
            )),
            $count(self::CHANGED),
        );
    }
}

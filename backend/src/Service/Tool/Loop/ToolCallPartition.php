<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

/**
 * Splits a turn's tool calls into the ones Synaplan runs and the ones it
 * hands back to the client, in the model's order.
 */
final readonly class ToolCallPartition
{
    /**
     * @param list<array<string, mixed>> $ours
     * @param list<array<string, mixed>> $client
     */
    public function __construct(
        public array $ours,
        public array $client,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>>        $calls
     * @param callable(array<string, mixed>): ?bool $owner true: ours, false: the client's, null: neither (dropped)
     */
    public static function of(iterable $calls, callable $owner): self
    {
        $ours = [];
        $client = [];
        foreach ($calls as $call) {
            $owned = $owner($call);
            if (true === $owned) {
                $ours[] = $call;
            } elseif (false === $owned) {
                $client[] = $call;
            }
        }

        return new self($ours, $client);
    }
}

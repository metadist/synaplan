<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * The payload of a turn's SSE `complete` event, assembled step by step.
 *
 * `usage_extra` lists the auxiliary usage of the turn (sorting, media, TTS)
 * and is kept in sync with the entries added here.
 */
final class StreamCompleteEvent
{
    /** @var list<array<string, mixed>> */
    private array $usageExtra = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    public function withUsageExtra(array $entries): void
    {
        $this->usageExtra = $entries;
        if ([] !== $entries) {
            $this->data['usage_extra'] = $entries;
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function addUsageExtra(array $entry): void
    {
        $this->usageExtra[] = $entry;
        $this->data['usage_extra'] = $this->usageExtra;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function usageExtra(): array
    {
        return $this->usageExtra;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }
}

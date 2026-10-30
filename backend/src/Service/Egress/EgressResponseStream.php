<?php

declare(strict_types=1);

namespace App\Service\Egress;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Streams the transport responses and yields the pending wrapper the caller
 * holds, so a `foreach` still matches the response `request()` returned.
 * A hop that was not followed yet is observed the first time it appears.
 */
final class EgressResponseStream implements ResponseStreamInterface
{
    /** @var array<int, true> */
    private array $seen = [];

    /**
     * @param array<int, EgressPendingResponse> $alias
     */
    public function __construct(
        private readonly ResponseStreamInterface $inner,
        private readonly array $alias,
    ) {
    }

    public function key(): ResponseInterface
    {
        $key = $this->inner->key();
        $wrapper = $this->alias[spl_object_id($key)] ?? null;
        if (!$wrapper instanceof EgressPendingResponse) {
            return $key;
        }
        $id = spl_object_id($wrapper);
        if (!isset($this->seen[$id])) {
            $this->seen[$id] = true;
            $wrapper->settle();
        }

        return $wrapper;
    }

    public function current(): ChunkInterface
    {
        return $this->inner->current();
    }

    public function next(): void
    {
        $this->inner->next();
    }

    public function valid(): bool
    {
        return $this->inner->valid();
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }
}

<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Session;

/**
 * Storage seam for pending, multi-turn command state (e.g. an invoice draft
 * awaiting confirmation). In Synaplan this is backed by `plugin_data`
 * (type=session); tests and examples use {@see InMemorySessionStore}.
 *
 * Stored value shape:
 *   ['handler' => string, 'step' => string, 'data' => array, 'expires_at' => int]
 */
interface SessionStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function load(string $key): ?array;

    /**
     * @param array<string, mixed> $state
     */
    public function save(string $key, array $state): void;

    public function clear(string $key): void;
}

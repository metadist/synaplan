<?php

declare(strict_types=1);

namespace App\Service\Media;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Cross-process cancellation flags for long-running media generation.
 *
 * A media generation (notably Higgsfield video) blocks the worker that serves
 * the SSE stream while it polls the provider for minutes. The user's "Stop"
 * click lands on a DIFFERENT worker, so it cannot reach the blocking poll loop
 * directly. This store bridges the two: the cancelling request sets a flag in
 * the shared cache, and the polling worker reads it every poll interval and
 * aborts (and tells the provider to cancel, so we stop being billed).
 *
 * Two scopes:
 *   - track-level  ("stop everything for this turn"): set by the global Stop
 *     button so every media node of the turn aborts.
 *   - node-level   ("stop just this step"): set by the per-card Stop button so a
 *     single multitask media node aborts while its siblings keep running.
 *
 * Flags are short-lived (a turn never outlives {@see self::TTL_SECONDS}); the
 * cache TTL is the only cleanup needed.
 */
final readonly class MediaCancellationStore
{
    private const PREFIX = 'media_cancel.';

    /** A turn cannot realistically outlive this; flags self-expire. */
    private const TTL_SECONDS = 1800;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Flag a cancellation. With a node id it is node-scoped (one multitask
     * step); without it the whole track is cancelled (every media node of the
     * turn).
     */
    public function requestCancel(string $trackId, ?string $nodeId = null): void
    {
        $trackId = trim($trackId);
        if ('' === $trackId) {
            return;
        }

        // A node id scopes the cancel to that single step (siblings keep running);
        // without one the whole turn is cancelled.
        if (null !== $nodeId && '' !== trim($nodeId)) {
            $this->set($this->nodeKey($trackId, trim($nodeId)));

            return;
        }

        $this->set($this->trackKey($trackId));
    }

    /**
     * True when this node (or its whole track) has been cancelled. A null node
     * id checks only the track scope.
     */
    public function isCancelled(string $trackId, ?string $nodeId = null): bool
    {
        $trackId = trim($trackId);
        if ('' === $trackId) {
            return false;
        }

        if ($this->cache->getItem($this->trackKey($trackId))->isHit()) {
            return true;
        }

        if (null !== $nodeId && '' !== trim($nodeId)) {
            return $this->cache->getItem($this->nodeKey($trackId, trim($nodeId)))->isHit();
        }

        return false;
    }

    private function set(string $key): void
    {
        $item = $this->cache->getItem($key);
        $item->set(true);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function trackKey(string $trackId): string
    {
        return self::PREFIX.'track.'.sha1($trackId);
    }

    private function nodeKey(string $trackId, string $nodeId): string
    {
        return self::PREFIX.'node.'.sha1($trackId.'|'.$nodeId);
    }
}

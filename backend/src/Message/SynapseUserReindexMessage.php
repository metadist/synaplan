<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async hint to refresh a single user's Synapse Routing topics.
 *
 * Dispatched by SynapseAutoIndexService whenever a user signs in
 * (any login flow — email/password, OAuth, Keycloak, refresh, …) so
 * topics that drifted from the active SYNAPSE_VECTORIZE model — e.g.
 * because the user copied prompts from another account, or the admin
 * just swapped the routing model — get re-embedded in the background
 * without blocking the auth response.
 *
 * The handler relies on SynapseIndexer::ensureUserTopicsFresh(), which
 * uses the per-topic source-hash to skip everything that is already
 * up-to-date — so the cost of a "nothing-to-do" login is essentially
 * a single repository read, not an embedding API round-trip.
 *
 * Queued in: async_index (low priority — Routing falls back to the
 * AI sorter while topics are stale, so this never has to be fast).
 */
final readonly class SynapseUserReindexMessage
{
    public function __construct(
        public int $userId,
    ) {
    }
}

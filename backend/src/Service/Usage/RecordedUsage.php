<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * Immutable result of {@see \App\Service\RateLimitService::recordUsage()}.
 *
 * Carries the token counts and both cost figures for the row that was just
 * written to BUSELOG, so the caller (e.g. StreamController) can surface the
 * charged per-message cost live in the SSE `complete` event without a second
 * DB round-trip. Costs are decimal strings (6 dp) for lossless transport.
 *
 * - rawCost:     the provider cost as stored in BUSELOG.BCOST.
 * - chargedCost: rawCost + operator markup (what the user is billed), i.e.
 *                consistent with the /statistics cost budget.
 */
final readonly class RecordedUsage
{
    public function __construct(
        public string $chargedCost,
        public string $rawCost,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
    ) {
    }
}

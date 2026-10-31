<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\AI\Pipeline\AiCallSource;
use App\Entity\User;
use App\Service\RateLimitService;

/**
 * Single migration seam for caller-side `RateLimitService::recordUsage()` calls
 * (`03_contracts.md` §1.5, S1 AU11).
 *
 * - Under `AI.USAGE_HOOK = legacy`: writes the `BUSELOG` row as today.
 * - Under `AI.USAGE_HOOK = shadow`: writes the `BUSELOG` row and compares it
 *   against {@see \App\AI\Pipeline\Hook\UsageHook}'s shadow computation,
 *   logging `ai.usage_mismatch` on any discrepancy.
 * - Under `AI.USAGE_HOOK = on`: a no-op for joined per-call paths (returning
 *   the row `UsageHook::after()` already wrote) and closes the per-turn
 *   {@see TurnUsageLedger} for per-turn `MESSAGES` rows; non-joined paths
 *   (`/v1/messages` and live voice until AU12) keep writing here.
 */
final class LegacyUsageWriter
{
    private static ?AiPipelineMeter $meter = null;

    private function __construct()
    {
    }

    public static function bind(?AiPipelineMeter $meter): void
    {
        self::$meter = $meter;
    }

    public static function meter(): ?AiPipelineMeter
    {
        return self::$meter;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function record(
        RateLimitService $rateLimitService,
        User $user,
        string $action,
        array $metadata = [],
        ?AiCallSource $source = null,
    ): RecordedUsage {
        $metadata = DemoCallContext::apply($metadata);
        $recorded = null !== self::$meter
            ? self::$meter->recordFromLegacyWriter($rateLimitService, $user, $action, $metadata, $source)
            : $rateLimitService->recordUsage($user, $action, $metadata);
        DemoCallContext::markWritten();

        return $recorded;
    }
}

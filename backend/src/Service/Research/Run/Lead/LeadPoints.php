<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\Stage\StageContext;

/**
 * Stores the statuses the lead named. A point called answered with no
 * finding stays open, because the report cannot cite what was never read.
 */
final readonly class LeadPoints
{
    /** @var list<string> */
    private const STATUSES = [PlanPoint::ANSWERED, PlanPoint::THIN, PlanPoint::CONFLICTING, PlanPoint::OPEN];

    public function apply(StageContext $ctx, mixed $points): void
    {
        if (!is_array($points)) {
            return;
        }
        foreach ($points as $point) {
            if (!is_array($point) || !is_string($point['id'] ?? null) || !is_string($point['status'] ?? null)) {
                continue;
            }
            if (!in_array($point['status'], self::STATUSES, true)) {
                continue;
            }
            $status = $point['status'];
            if (PlanPoint::ANSWERED === $status && !$ctx->state->ledger->hasEvidenceFor($point['id'])) {
                $status = PlanPoint::OPEN;
            }
            try {
                $note = is_string($point['note'] ?? null) ? $point['note'] : '';
                $ctx->state->ledger->markPoint($point['id'], $status, $note);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $ctx->events->append($ctx->state->ref->id, 'point', [
                'id' => $point['id'],
                'status' => $status,
            ]);
        }
    }
}

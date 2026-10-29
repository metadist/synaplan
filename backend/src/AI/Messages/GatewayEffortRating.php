<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Service\Message\Effort\EffortVerdict;
use App\Service\Message\Effort\GateDecision;

/**
 * What the gateway decided for one opted-in turn: the sorter's rating,
 * the gate's decision, and — when a research started — the notice
 * sentence that stands in for the model's answer.
 */
final readonly class GatewayEffortRating
{
    public function __construct(
        public EffortVerdict $verdict,
        public GateDecision $decision,
        public ?string $notice,
    ) {
    }

    public function startedRun(): bool
    {
        return $this->decision->startedRun() && null !== $this->notice;
    }

    /** @return array<string, string> the response headers this rating adds */
    public function headers(): array
    {
        $headers = [GatewayEffortHeader::HEADER => $this->verdict->effort->value];
        if (null !== $this->decision->runId) {
            $headers[GatewayEffortHeader::RUN_HEADER] = $this->decision->runId;
        }
        if (null !== $this->decision->heldBackBy()) {
            $headers[GatewayEffortHeader::LIMIT_HEADER] = $this->decision->heldBackBy();
        }

        return $headers;
    }
}

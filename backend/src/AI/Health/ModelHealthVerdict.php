<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Entity\ModelHealth;

/**
 * The health monitor's conclusion about one model in one run.
 */
final readonly class ModelHealthVerdict
{
    public function __construct(
        public int $modelId,
        public string $service,
        public string $modelName,
        public string $providerId,
        public string $tag,
        public ModelHealthState $state,
        public ?FailureKind $kind,
        public string $message,
        public string $source = ModelHealth::SOURCE_PROBE,
        /**
         * May the automation act on this verdict, not just report it?
         *
         * True only where the provider itself gave a verdict about this exact
         * model: it rejected the id, or real calls to it failed permanently.
         * Absence from a published model list never qualifies — Google leaves
         * Imagen out of /v1beta/models and serves it regardless, so "absent
         * from the list" and "retired" are not the same claim.
         */
        public bool $safeToDisable = false,
        public bool $autoDisabled = false,
        public bool $reEnabled = false,
    ) {
    }

    public function needsAttention(): bool
    {
        return $this->state->needsAttention();
    }
}

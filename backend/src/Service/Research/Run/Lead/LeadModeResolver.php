<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\ResearchDepth;

/**
 * Picks tool mode or round mode. `auto` uses tool mode only for a listed
 * model on a run that has rounds. A quick run stays in round mode unless
 * an administrator forces tools. The list is empty until a model passes
 * the conformance suite, so the default is round mode.
 */
final readonly class LeadModeResolver
{
    public function __construct(
        private LeadSettings $settings,
        private LeadCatalog $catalog,
    ) {
    }

    public function resolve(int $leadModelId, ResearchDepth $depth): LeadMode
    {
        return $this->decide($this->settings->mode(), $this->settings->models(), $this->catalog->key($leadModelId), $depth);
    }

    /**
     * @param list<string> $models
     */
    public function decide(string $mode, array $models, ?string $modelKey, ResearchDepth $depth): LeadMode
    {
        if ('rounds' === $mode) {
            return LeadMode::Rounds;
        }
        if ('tools' === $mode) {
            return LeadMode::Tools;
        }
        if (ResearchDepth::Quick === $depth || null === $modelKey) {
            return LeadMode::Rounds;
        }

        return in_array($modelKey, $models, true) ? LeadMode::Tools : LeadMode::Rounds;
    }
}

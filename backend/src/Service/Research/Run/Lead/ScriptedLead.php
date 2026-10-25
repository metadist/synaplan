<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Stage\ResearchLeadCaller;
use App\Service\Research\Run\Stage\ResearchLeadTurn;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Recorded lead turns for the conformance suite. No provider is called. */
#[Exclude]
final class ScriptedLead implements ResearchLeadCaller
{
    /** @var list<ResearchLeadTurn> */
    public array $script = [];

    /** @var list<list<array<string, mixed>>> */
    public array $seen = [];

    public function turn(int $userId, int $modelId, array $messages, array $tools): ResearchLeadTurn
    {
        $this->seen[] = $messages;

        return array_shift($this->script) ?? ResearchLeadTurn::failed();
    }
}

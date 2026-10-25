<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Ledger\PassageSplitter;
use App\Service\Research\Run\Ledger\PassageVerifier;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\Stage\AcceptedEvidence;
use App\Service\Research\Run\Stage\ReadStage;
use App\Service\Research\Run\Stage\ResearchPage;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageCall;
use App\Service\Research\Run\Stage\StageContext;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** One standard run wired to scripted search, pages and models. */
#[Exclude]
final class LeadBench
{
    public function __construct(
        public StageContext $ctx,
        public ScriptedSearch $search,
        public ScriptedPages $pages,
        public ScriptedModelCaller $reader,
        public ScriptedLead $lead,
        public LeadStage $stage,
        public LeadToolbox $tools,
        public LeadConformanceKeys $keys,
    ) {
    }

    public static function open(): self
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $clock = new MockClock($created);
        $keys = new LeadConformanceKeys();
        $store = new ResearchStateStore($keys, $clock);
        $budget = ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'What does one unit cost?', null, $created),
            $budget,
        );
        $state->ledger->addPoint('Unit price', 'Needed.');
        $search = new ScriptedSearch();
        $pages = new ScriptedPages();
        $reader = new ScriptedModelCaller();
        $lead = new ScriptedLead();
        $read = new ReadStage(new StageCall($reader), $pages, new PassageSplitter(), new AcceptedEvidence(new PassageVerifier()), $store);
        $tools = new LeadToolbox(new LeadSearch($search), new LeadRead($read));
        $modes = new LeadModeResolver(new FixedLeadSettings('tools'), new FixedLeadCatalog('anthropic:claude:chat'));
        $stage = new LeadStage($lead, $tools, $modes, new ResearchInputBudget());
        $ctx = new StageContext($state, new ResearchRoles(1, 2, 3, 4, 5, false, []), new ResearchEventLog($keys), $created, new ResearchStop());

        return new self($ctx, $search, $pages, $reader, $lead, $stage, $tools, $keys);
    }

    public function pricePage(string $url): string
    {
        $id = $this->ctx->state->ledger->addSource(Source::web($url, 'Price list'));
        $source = $this->ctx->state->ledger->source($id);
        if (!$source instanceof Source || null === $source->url) {
            throw new \RuntimeException('The conformance fixture needs the source it just added.');
        }
        $this->pages->pages[$source->url] = new ResearchPage(false, "The unit price is 12.40 EUR.\n\nSECRET PAGE BODY THAT MUST NOT LEAK", '', []);

        return $id;
    }
}

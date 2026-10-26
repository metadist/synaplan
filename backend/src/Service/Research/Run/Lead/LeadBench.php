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
use App\Service\Research\Run\Stage\ResearchHit;
use App\Service\Research\Run\Stage\ResearchLeadCaller;
use App\Service\Research\Run\Stage\ResearchModelReply;
use App\Service\Research\Run\Stage\ResearchPage;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageCall;
use App\Service\Research\Run\Stage\StageContext;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One standard run wired to scripted search, pages and reader. The lead is
 * scripted too, unless a caller is passed: the conformance command passes
 * the live one, so only the lead model is ever called.
 */
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

    public static function open(?ResearchLeadCaller $caller = null, int $leadModelId = 3, int $userId = 7): self
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $clock = new MockClock($created);
        $keys = new LeadConformanceKeys();
        $store = new ResearchStateStore($keys, $clock);
        $budget = ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard), $created);
        $budget->at($created);
        $state = ResearchRunState::open(
            ResearchRunRef::start($userId, ResearchDepth::Standard, 'What does one Maker X100 unit cost, in euros?', null, $created),
            $budget,
        );
        $state->ledger->addPoint('Unit price of the Maker X100', 'Needed.');
        $search = new ScriptedSearch();
        $pages = new ScriptedPages();
        $reader = new ScriptedModelCaller();
        $lead = new ScriptedLead();
        $read = new ReadStage(new StageCall($reader), $pages, new PassageSplitter(), new AcceptedEvidence(new PassageVerifier()), $store);
        $tools = new LeadToolbox(new LeadSearch($search), new LeadRead($read));
        $modes = new LeadModeResolver(new FixedLeadSettings('tools'), new FixedLeadCatalog('anthropic:claude:chat'));
        $stage = new LeadStage($caller ?? $lead, $tools, $modes, new ResearchInputBudget());
        $ctx = new StageContext($state, new ResearchRoles(1, 2, $leadModelId, 4, 5, false, []), new ResearchEventLog($keys), $created, new ResearchStop());

        return new self($ctx, $search, $pages, $reader, $lead, $stage, $tools, $keys);
    }

    public function pricePage(string $url): string
    {
        $id = $this->ctx->state->ledger->addSource(Source::web($url, 'Price list'));
        $source = $this->ctx->state->ledger->source($id);
        if (!$source instanceof Source || null === $source->url) {
            throw new \RuntimeException('The conformance fixture needs the source it just added.');
        }
        $this->servePage($source->url);

        return $id;
    }

    public function servePage(string $url): void
    {
        $this->pages->pages[$url] = new ResearchPage(false, "The unit price is 12.40 EUR.\n\nSECRET PAGE BODY THAT MUST NOT LEAK", '', []);
    }

    /** The ledger id of a found address, after a search added it. */
    public function sourceId(string $url): string
    {
        foreach ($this->ctx->state->ledger->sources() as $source) {
            if ($url === $source->url) {
                return $source->id;
            }
        }

        throw new \RuntimeException('The conformance fixture expected '.$url.' among the found sources.');
    }

    public static function hit(string $url, string $title, string $snippet): ResearchHit
    {
        $hit = ResearchHit::from($url, $title, $snippet, '2026-01-01');
        if (!$hit instanceof ResearchHit) {
            throw new \RuntimeException('The conformance fixture needs a valid address.');
        }

        return $hit;
    }

    /** A reader answer that settles p1 from the first passage of the price page. */
    public static function evidence(): ResearchModelReply
    {
        $data = [
            'source_id' => 's1',
            'useful' => true,
            'published' => null,
            'kind' => 'vendor',
            'findings' => [[
                'point' => 'p1',
                'fact' => 'The unit price is 12.40 EUR.',
                'passages' => ['§1'],
                'quote' => ['text' => 'The unit price is 12.40 EUR.', 'passage' => '§1'],
                'confidence' => 'high',
            ]],
            'leads' => [],
            'flags' => [],
        ];

        return new ResearchModelReply(true, true, $data, (string) json_encode($data), 'test:reader', null);
    }
}

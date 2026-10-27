<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\ConfigRepository;
use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchRunView;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;
use App\Service\Research\Run\Stage\FollowUpTask;

final class ResearchFollowUpTest extends ResearchUnitCase
{
    private ResearchRunService $runs;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(7);
        $this->runs = new ResearchRunService(
            $this->store,
            $this->bus,
            $this->events,
            new ResearchConfig($this->createStub(ConfigRepository::class), '1'),
            $this->chats(),
            $this->clock,
            new SilentResearchReport(),
        );
    }

    public function testAQuestionWithNotesDoesNotSearch(): void
    {
        $parent = $this->finished();
        $parent->ledger->setBrief(new ResearchBrief('Prices', 'en', 'report'));
        $parent->ledger->addPoint('Price');
        $source = $parent->ledger->addSource(Source::web('https://maker.example/price', 'Price'));
        $parent->ledger->addEvidence(new EvidenceCard('x', $source, 'p1', 'The price is 12.', ['§1'], []));
        $parent->ledger->setReport('The price is 12. [e1]');
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'question',
            'text' => 'What is the warranty?',
        ]);

        self::assertSame(ResearchDepth::Quick, $next->ref->depth);
        self::assertSame(ResearchStatus::Writing, $next->status);
        self::assertSame(ResearchUnitPlan::WRITE, $this->bus->sent[1]['unit']);
        self::assertSame('question', $next->followUp);
        self::assertSame('s1', $next->ledger->sources()[0]->id);
        self::assertSame('e1', $next->ledger->evidenceForWriter()['p1'][0]['id']);
        self::assertSame(2, $next->budget->searchesLeft());
        self::assertSame('The price is 12. [e1]', $next->priorReport);
        $stored = ResearchRunState::decode($next->encode());
        self::assertInstanceOf(ResearchRunState::class, $stored);
        self::assertSame('question', $stored->followUp);
        self::assertSame('The price is 12. [e1]', $stored->priorReport);

        $next->ledger->setReport('The warranty is two years. [e1]');
        $next->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $view = ResearchRunView::one($next, false, false, null);
        self::assertTrue($view['can_add_to_report']);
        self::assertSame('question', $view['follow_up']);
    }

    public function testAQuestionWithNothingInTheLedgerSearchesOnce(): void
    {
        $parent = $this->finished();

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'question',
            'text' => 'What is the warranty?',
        ]);

        self::assertSame(ResearchDepth::Quick, $next->ref->depth);
        self::assertSame(ResearchStatus::Briefing, $next->status);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[1]['unit']);
        self::assertSame(1, $next->budget->searchesLeft());
        self::assertFalse(ResearchRunView::one($next, false, false, null)['can_add_to_report']);
    }

    public function testARevisionKeepsTheReport(): void
    {
        $parent = $this->finished();
        $parent->ledger->setReport('The price is 12. [e1]');
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'revise',
            'text' => 'Make it shorter.',
        ]);

        self::assertSame(ResearchDepth::Standard, $next->ref->depth);
        self::assertSame(ResearchStatus::Writing, $next->status);
        self::assertSame(ResearchUnitPlan::WRITE, $this->bus->sent[1]['unit']);
        self::assertSame('revise', $next->followUp);
        self::assertSame('The price is 12. [e1]', $next->priorReport);
        self::assertNull($next->ledger->report());
        self::assertStringContainsString('keep its citation', FollowUpTask::text($next));
        self::assertStringContainsString('[e1]', FollowUpTask::text($next));
    }

    public function testDeeperContinuesThePointNumbers(): void
    {
        $parent = $this->finished();
        $parent->ledger->addPoint('Price');
        $parent->ledger->addQuery('p1', 'unit price', 'files', 1);
        $parent->ledger->setReport("The price is 12. [e1]\n\n## Not confirmed\n\nThe delivery date is June.\n\n1 of 2 statements are backed by a source.\n\nA model of the same provider did the fact-check. This project has no second provider.");
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'deeper',
            'text' => 'Look further.',
        ]);

        self::assertSame(ResearchDepth::Thorough, $next->ref->depth);
        self::assertSame(ResearchStatus::Gathering, $next->status);
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[1]['unit']);
        $points = $next->ledger->points();
        self::assertSame('p1', $points[0]->id);
        self::assertSame('p2', $points[1]->id);
        self::assertSame('The delivery date is June.', $points[1]->title);
        self::assertSame('files', $points[1]->queries[0]['scope']);
        self::assertCount(2, $points);
    }

    public function testRefreshReopensSourcesOlderThanTheTimeFrame(): void
    {
        $parent = $this->finished();
        $parent->ledger->setBrief(new ResearchBrief('Prices', 'en', 'report', null, ['from' => '2024-01-01', 'to' => '2026-01-01']));
        $parent->ledger->addPoint('Old price');
        $parent->ledger->addPoint('New price');
        $old = $parent->ledger->addSource(Source::web('https://maker.example/old', 'Old', '2020-05-01'));
        $fresh = $parent->ledger->addSource(Source::web('https://maker.example/new', 'New', '2025-05-01'));
        $parent->ledger->markSource($old, Source::READ);
        $parent->ledger->markSource($fresh, Source::READ);
        $parent->ledger->addEvidence(new EvidenceCard('x', $old, 'p1', 'The old price is 9.', ['§1'], []));
        $parent->ledger->addEvidence(new EvidenceCard('y', $fresh, 'p2', 'The new price is 12.', ['§1'], []));
        $parent->ledger->addQuery('p1', 'old price', 'web', 1);
        $parent->ledger->addQuery('p2', 'new price', 'web', 1);
        $parent->ledger->setReport('The price was 9 and is now 12.');
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'refresh']);

        self::assertSame(ResearchDepth::Standard, $next->ref->depth);
        self::assertSame(Source::FOUND, $next->ledger->source($old)?->status);
        self::assertSame(Source::READ, $next->ledger->source($fresh)?->status);
        $queries = array_map(static fn ($query): string => $query->pointId, $next->ledger->queries());
        self::assertSame(['p2'], $queries);
        self::assertSame('refresh', $next->followUp);
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[1]['unit']);
        self::assertSame(Source::READ, $parent->ledger->source($old)?->status);
    }

    public function testAFollowUpWaitsUntilTheResearchFinishes(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);

        try {
            $this->runs->followUp($this->user, $started->ref->id, ['type' => 'refresh']);
            self::fail('A follow-up on a running research should be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(400, $rejected->status);
            self::assertSame('Wait until this research finishes. Then you can ask a follow-up.', $rejected->getMessage());
        }
    }

    public function testAFollowUpOnSomeoneElsesRunIsNotFound(): void
    {
        $parent = $this->finished();
        $other = $this->createStub(User::class);
        $other->method('getId')->willReturn(8);

        $this->expectException(ResearchRunNotFound::class);
        $this->runs->followUp($other, $parent->ref->id, ['type' => 'refresh']);
    }

    private function finished(): ResearchRunState
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);
        $parent = $this->store->load($started->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $parent);
        $parent->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $this->store->save($parent);

        return $parent;
    }

    /**
     * @return array{question: string, depth: string, chat_id: int}
     */
    private function body(): array
    {
        return ['question' => 'Which suppliers deliver this in Germany?', 'depth' => 'standard', 'chat_id' => 11];
    }

    private function chats(): ChatRepository
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturnCallback(static fn (int $id): ?Chat => 11 === $id ? $chat : null);

        return $chats;
    }
}

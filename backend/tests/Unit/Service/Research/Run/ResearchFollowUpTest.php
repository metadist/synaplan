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

    public function testAQuestionTheNotesCoverDoesNotSearch(): void
    {
        $parent = $this->withWarranty();

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'question',
            'text' => 'How long is the warranty?',
        ]);

        self::assertSame(ResearchDepth::Quick, $next->ref->depth);
        self::assertSame(ResearchStatus::Writing, $next->status);
        self::assertSame(ResearchUnitPlan::WRITE, $this->bus->sent[1]['unit']);
        self::assertSame('question', $next->followUp);
        self::assertSame('How long is the warranty?', $next->ref->question);
        self::assertSame('s1', $next->ledger->sources()[0]->id);
        self::assertSame('e1', $next->ledger->evidenceForWriter()['p1'][0]['id']);
        self::assertSame([0, 0], $next->budget->toArray()['searches']);
        self::assertSame([], $next->ledger->outline());
        self::assertSame('The warranty is two years. [e1]', $next->priorReport);
        self::assertNull($next->ledger->report());
        self::assertNull($next->ledger->check());
        self::assertSame(ResearchUnitPlan::WRITE, ResearchUnitPlan::resume($next));
        $stored = ResearchRunState::decode($next->encode());
        self::assertInstanceOf(ResearchRunState::class, $stored);
        self::assertSame('question', $stored->followUp);
        self::assertSame('The warranty is two years. [e1]', $stored->priorReport);
        self::assertFalse(ResearchRunView::one($next, false, false, null)['can_add_to_report']);

        $next->ledger->setReport('The warranty is two years. [e1]');
        $next->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $view = ResearchRunView::one($next, false, false, null);
        self::assertTrue($view['can_add_to_report']);
        self::assertSame('question', $view['follow_up']);
    }

    public function testAQuestionTheNotesDoNotCoverSearchesOnceForTheQuestion(): void
    {
        $parent = $this->withWarranty();
        $parent->ledger->addPoint('Delivery', '', [['q' => 'delivery time', 'language' => 'en', 'scope' => 'web']]);
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'question',
            'text' => 'And in Austria?',
        ]);

        self::assertSame(ResearchStatus::Gathering, $next->status);
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[1]['unit']);
        self::assertSame([0, 1], $next->budget->toArray()['searches']);
        $points = $next->ledger->points();
        self::assertSame('p3', $points[2]->id);
        self::assertSame('And in Austria?', $points[2]->queries[0]['q']);
        self::assertSame([], $points[1]->queries, 'A search the parent never ran must not take the one search.');
        self::assertTrue($next->ledger->hasPlannedSearch());
    }

    public function testAQuestionWithNothingInTheLedgerBriefsAndSearchesOnce(): void
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
        self::assertSame('What is the warranty?', $next->ledger->points()[0]->title);
        self::assertFalse(ResearchRunView::one($next, false, false, null)['can_add_to_report']);
    }

    public function testAStoppedQuestionCannotBeAddedToTheReport(): void
    {
        $parent = $this->withWarranty();
        $next = $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'question', 'text' => 'How long is the warranty?']);

        $next->ledger->setReport('The warranty is two years. [e1]');
        $next->finish(ResearchStatus::Stopped, new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER));

        self::assertFalse(ResearchRunView::one($next, false, false, null)['can_add_to_report']);
    }

    public function testARevisionWritesFromThePriorReport(): void
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
        self::assertSame('Which suppliers deliver this in Germany?', $next->ref->question);
        self::assertSame('The price is 12. [e1]', $next->priorReport);
        self::assertNull($next->ledger->report());
        self::assertSame('The price is 12. [e1]', $parent->ledger->report());
        self::assertStringContainsString('keep its citation', FollowUpTask::text($next));
        self::assertStringContainsString('[e1]', FollowUpTask::text($next));
    }

    public function testARevisionWithoutAReportIsRefused(): void
    {
        $parent = $this->finished();

        try {
            $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'revise', 'text' => 'Make it shorter.']);
            self::fail('A revision without a report should be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(409, $rejected->status);
            self::assertSame('This research has no report to revise. Ask a question or look deeper instead.', $rejected->getMessage());
        }
        self::assertCount(1, $this->bus->sent);
    }

    public function testDeeperTurnsEachNotConfirmedItemIntoAPoint(): void
    {
        $parent = $this->finished();
        $parent->ledger->addPoint('Price');
        $parent->ledger->addQuery('p1', 'unit price', 'files', 1);
        $parent->ledger->setReport(
            "The price is 12. [e1]\n\n## Not confirmed\n\n- The delivery date is June. [e2]\n- The delivery date is June.\n1. The maker is in Lyon.\n\nThe warranty was not found.\n\n"
            ."1 of 4 statements are backed by a source. 3 were removed because the check could not confirm them in a source.\n\n"
            .'A model of the same provider did the fact-check. This project has no second provider.',
        );
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, [
            'type' => 'deeper',
            'text' => 'Look at Austrian makers too.',
        ]);

        self::assertSame(ResearchDepth::Thorough, $next->ref->depth);
        self::assertSame(ResearchStatus::Gathering, $next->status);
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[1]['unit']);
        $titles = array_map(static fn ($point): string => $point->id.' '.$point->title, $next->ledger->points());
        self::assertSame([
            'p1 Price',
            'p2 The delivery date is June.',
            'p3 The maker is in Lyon.',
            'p4 The warranty was not found.',
            'p5 Look at Austrian makers too.',
        ], $titles);
        self::assertSame('files', $next->ledger->points()[1]->queries[0]['scope']);
        self::assertStringContainsString('Look at Austrian makers too.', FollowUpTask::text($next));
    }

    public function testDeeperWithoutTextSearchesTheQuestionAgain(): void
    {
        $parent = $this->finished();
        $parent->ledger->addPoint('Price');
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'deeper']);

        self::assertSame(ResearchDepth::Thorough, $next->ref->depth);
        self::assertSame('Which suppliers deliver this in Germany?', $next->ledger->points()[1]->title);
        self::assertSame('Which suppliers deliver this in Germany?', $next->ref->question);
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
        self::assertNull($next->ledger->report());
    }

    public function testAFollowUpWaitsUntilTheResearchFinishes(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);

        try {
            $this->runs->followUp($this->user, $started->ref->id, ['type' => 'refresh']);
            self::fail('A follow-up on a running research should be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(409, $rejected->status);
            self::assertSame('Wait until this research finishes. Then you can ask a follow-up.', $rejected->getMessage());
        }
    }

    public function testARepeatedIdempotencyKeyStartsOneFollowUp(): void
    {
        $parent = $this->withWarranty();

        $first = $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'question', 'text' => 'How long is the warranty?'], 'fu-1');
        $again = $this->runs->followUp($this->user, $parent->ref->id, ['type' => 'question', 'text' => 'How long is the warranty?'], 'fu-1');

        self::assertSame($first->ref->id, $again->ref->id);
        self::assertCount(2, $this->bus->sent);
    }

    public function testAFollowUpOnSomeoneElsesRunIsNotFound(): void
    {
        $parent = $this->finished();
        $other = $this->createStub(User::class);
        $other->method('getId')->willReturn(8);

        $this->expectException(ResearchRunNotFound::class);
        $this->runs->followUp($other, $parent->ref->id, ['type' => 'refresh']);
    }

    private function withWarranty(): ResearchRunState
    {
        $parent = $this->finished();
        $parent->ledger->setBrief(new ResearchBrief('Warranty', 'en', 'report'));
        $parent->ledger->setOutline(['Warranty']);
        $parent->ledger->addPoint('Warranty');
        $source = $parent->ledger->addSource(Source::web('https://maker.example/warranty', 'Warranty'));
        $parent->ledger->addEvidence(new EvidenceCard('x', $source, 'p1', 'The warranty is two years.', ['§1'], []));
        $parent->ledger->setReport('The warranty is two years. [e1]');
        $parent->ledger->setCheck(1, 1, 0);
        $this->store->save($parent);

        return $parent;
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

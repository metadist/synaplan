<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\User;
use App\Message\ContinueSavedTaskAfterResearch;
use App\Repository\ChatRepository;
use App\Repository\ConfigRepository;
use App\Service\RAG\RagScopeResolver;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchQuestion;
use App\Service\Research\Run\ResearchQuestions;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchSavedTaskSignal;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchUnitPlan;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResearchRunServiceTest extends ResearchUnitCase
{
    private ResearchRunService $runs;

    private User $user;

    private SilentResearchReport $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new SilentResearchReport();
        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(7);
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturnCallback(static fn (int $id): ?Chat => 11 === $id ? $chat : null);
        $this->runs = new ResearchRunService(
            $this->store,
            $this->bus,
            $this->events,
            new ResearchConfig($this->createStub(ConfigRepository::class), '1'),
            $chats,
            $this->clock,
            $this->reports,
        );
    }

    public function testARepeatedStartReturnsTheSameRun(): void
    {
        $first = $this->runs->start($this->user, $this->body(), true, 'retry-1');
        $second = $this->runs->start($this->user, $this->body(), true, 'retry-1');

        self::assertSame($first->ref->id, $second->ref->id);
        self::assertCount(1, $this->bus->sent);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[0]['unit']);
        self::assertSame(0, $this->bus->sent[0]['delayMs']);
    }

    public function testTheSessionRouteNeedsTheCallersChat(): void
    {
        try {
            $this->runs->start($this->user, ['question' => 'Prices?', 'depth' => 'standard'], true, null);
            self::fail('A session start without a chat should be rejected.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(400, $rejected->status);
            self::assertSame('chat_id', $rejected->field);
        }

        try {
            $this->runs->start($this->user, ['question' => 'Prices?', 'depth' => 'standard', 'chat_id' => 11], false, null);
            self::fail('An API start must not take a chat.');
        } catch (ResearchRejected $rejected) {
            self::assertSame('chat_id', $rejected->field);
        }
    }

    public function testAStartWithASharedFolderTheCallerCannotUseIsRefused(): void
    {
        $scopes = $this->createStub(RagScopeResolver::class);
        $scopes->method('canUsePickerKey')->willReturnCallback(static fn (int $userId, string $key): bool => 'DESKTOP:1' === $key);
        $runs = new ResearchRunService($this->store, $this->bus, $this->events, new ResearchConfig($this->createStub(ConfigRepository::class), '1'), $this->chats(), $this->clock, $this->reports, null, $scopes);

        try {
            $runs->start($this->user, $this->body() + ['sources' => ['knowledge' => ['DESKTOP:1', 'shared:9:Team']]], true, null);
            self::fail('A folder that is not shared with the caller must not start a run.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(400, $rejected->status);
            self::assertSame('sources', $rejected->field);
        }
        self::assertCount(0, $this->bus->sent);

        $started = $runs->start($this->user, $this->body() + ['sources' => ['knowledge' => ['DESKTOP:1']]], true, null);
        self::assertSame(['DESKTOP:1'], $started->knowledgeKeys);
    }

    public function testListAndDeleteStayOnTheCallersRuns(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);
        $listed = $this->runs->listRecent($this->user);

        self::assertSame($started->ref->id, $listed[0]['id']);
        self::assertSame('standard', $listed[0]['depth']);

        $this->runs->delete($this->user, $started->ref->id);

        $this->expectException(ResearchRunNotFound::class);
        $this->runs->state($this->user, $started->ref->id);
    }

    public function testCancelFinishesARunThatIsNotBeingWorkedOn(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);

        $stopped = $this->runs->cancel($this->user, $started->ref->id);

        self::assertSame(ResearchOutcome::STOPPED_BY_USER, $stopped->outcome?->code);
        self::assertSame(['stopped'], $this->reports->statuses);
        self::assertSame(2, $this->store->load($started->ref->id)?->expectedSeq);
    }

    public function testCancelLeavesARunningStepToTheWorker(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);
        self::assertNotNull($this->store->claim($started->ref->id, 1, 1000));

        $state = $this->runs->cancel($this->user, $started->ref->id);

        self::assertSame(ResearchStatus::Briefing, $state->status);
        self::assertTrue($this->store->stopRequested($started->ref->id));
        self::assertSame([], $this->reports->statuses);
    }

    public function testTheListCanShowOnlyRunsThatAreStillGoingNewestFirst(): void
    {
        $older = $this->runs->start($this->user, $this->body(), true, null);
        $this->clock->sleep(60);
        $newer = $this->runs->start($this->user, $this->body(), true, null);
        $this->runs->cancel($this->user, $older->ref->id);

        self::assertSame([$newer->ref->id, $older->ref->id], array_column($this->runs->listRecent($this->user), 'id'));
        self::assertSame([$newer->ref->id], array_column($this->runs->listRecent($this->user, true), 'id'));
    }

    public function testAnApprovedPlanContinuesUnderTheClaim(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'approve_plan']);

        self::assertSame(ResearchStatus::Gathering, $state->status);
        self::assertSame(3, $state->expectedSeq);
        self::assertSame(['unit' => ResearchUnitPlan::ROUND, 'seq' => 3], [
            'unit' => $this->bus->sent[1]['unit'],
            'seq' => $this->bus->sent[1]['seq'],
        ]);
    }

    public function testAnAnswerContinuesAndASecondQuestionKeepsWaiting(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $questions = ResearchQuestion::collect([
            ['text' => 'Which year?', 'options' => ['2025', '2026'], 'allow_text' => false],
            ['text' => 'Which shop?', 'options' => ['Alpha', 'Beta'], 'allow_text' => true],
        ], 2);
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingAnswer, $opened->ledger, $opened->budget, 2, 1);
        $waiting->questions = new ResearchQuestions($questions, ResearchUnitPlan::BRIEF_PLAN, ResearchStatus::Planning->value);
        $this->store->save($waiting);

        $still = $this->runs->input($this->user, $opened->ref->id, ['type' => 'answer', 'question' => 'q1', 'choice' => '2026']);

        self::assertSame(ResearchStatus::WaitingAnswer, $still->status);
        self::assertCount(1, $this->bus->sent);

        $next = $this->runs->input($this->user, $opened->ref->id, ['type' => 'skip_question', 'question' => 'q2']);

        self::assertSame(ResearchStatus::Planning, $next->status);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[1]['unit']);
        self::assertSame('2026', $next->replies[0]['answer']);
        self::assertSame('Skipped', $next->replies[1]['answer']);
    }

    public function testAnEditedPlanKeepsTheNamedPointAndAddsTheNewOne(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $opened->ledger->addPoint('Unit price');
        $opened->ledger->addPoint('Warranty');
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'edit_plan', 'points' => [
            ['id' => 'p1', 'title' => 'Net price'],
            ['title' => 'Delivery times'],
        ]]);

        self::assertSame(ResearchStatus::Gathering, $state->status);
        self::assertSame(['Net price', 'Delivery times'], array_map(
            static fn (PlanPoint $point): string => $point->title,
            $state->ledger->points(),
        ));
        self::assertSame(ResearchUnitPlan::ROUND, $this->bus->sent[1]['unit']);
    }

    public function testAnAddedOrRenamedPointIsSearchedByItsTitle(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $opened->ledger->addPoint('Unit price', '', [['q' => 'unit price 2026', 'language' => 'en']]);
        $opened->ledger->addPoint('Warranty', '', [['q' => 'warranty terms', 'language' => 'en']]);
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'edit_plan', 'points' => [
            ['id' => 'p1', 'title' => 'Net price'],
            ['id' => 'p2'],
            ['title' => 'Delivery times'],
        ]]);

        $queries = array_map(
            static fn (PlanPoint $point): array => array_column($point->queries, 'q'),
            $state->ledger->points(),
        );
        self::assertSame([['Net price'], ['warranty terms'], ['Delivery times']], $queries);
    }

    public function testAnEditedPlanCannotGrowPastTheDepth(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $opened->ledger->addPoint('Unit price');
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);
        $points = array_map(static fn (int $n): array => ['title' => 'Point '.$n], range(1, 50));

        try {
            $this->runs->input($this->user, $opened->ref->id, ['type' => 'edit_plan', 'points' => $points]);
            self::fail('A plan larger than the depth allows must be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(400, $rejected->status);
            self::assertSame('points', $rejected->field);
        }
        self::assertSame(ResearchStatus::WaitingPlan, $this->store->load($opened->ref->id)?->status);
        self::assertCount(1, $this->bus->sent);
    }

    public function testWriteNowOnAWaitingPlanGoesToTheReport(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'stop_gathering']);

        self::assertSame(ResearchStatus::Writing, $state->status);
        self::assertTrue($this->store->writeNowRequested($opened->ref->id));
        self::assertSame(ResearchUnitPlan::WRITE, $this->bus->sent[1]['unit']);
    }

    public function testWriteNowWhileGatheringOnlyRaisesTheFlag(): void
    {
        $opened = $this->runs->start($this->user, $this->body(), true, null);
        $gathering = new ResearchRunState($opened->ref, ResearchStatus::Gathering, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($gathering);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'stop_gathering']);

        self::assertSame(ResearchStatus::Gathering, $state->status);
        self::assertTrue($this->store->writeNowRequested($opened->ref->id));
        self::assertCount(1, $this->bus->sent);
    }

    public function testAnAutomaticRunStartsOnTheStandardRowWithoutQuestions(): void
    {
        $state = $this->runs->start($this->user, ['question' => 'Compare A and B', 'depth' => 'auto', 'chat_id' => 11, 'hint' => 'brief'], true, null);

        self::assertSame(ResearchDepth::Auto, $state->ref->depth);
        self::assertSame('brief', $state->hint);
        self::assertNull($state->ceiling);
        self::assertSame(0, $state->budget->extras()->questions);
        self::assertSame(10, $state->budget->limits()->searches);
        self::assertSame('auto', $this->store->load($state->ref->id)?->ref->depth->value);
    }

    public function testAnUnknownHintIsRefused(): void
    {
        $this->expectException(ResearchRejected::class);

        $this->runs->start($this->user, ['question' => 'Compare A and B', 'depth' => 'auto', 'chat_id' => 11, 'hint' => 'fast'], true, null);
    }

    public function testAnswerNowBeforeTheFirstSourceClosesWithoutAReport(): void
    {
        $started = $this->runs->start($this->user, ['question' => 'Compare A and B', 'depth' => 'auto', 'chat_id' => 11], true, null);

        $closed = $this->runs->input($this->user, $started->ref->id, ['type' => 'answer_now']);

        self::assertSame(ResearchStatus::Stopped, $closed->status);
        self::assertSame(ResearchOutcome::ANSWERED_WITHOUT_RESEARCH, $closed->outcome?->code);
        self::assertSame(['stopped'], $this->reports->statuses);
    }

    public function testAnswerNowWithASourceReadWritesFromWhatWasFound(): void
    {
        $opened = $this->runs->start($this->user, ['question' => 'Compare A and B', 'depth' => 'auto', 'chat_id' => 11], true, null);
        $opened->ledger->addSource(Source::web('https://maker.example/price', 'Price list'));
        $gathering = new ResearchRunState($opened->ref, ResearchStatus::Gathering, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($gathering);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'answer_now']);

        self::assertSame(ResearchStatus::Gathering, $state->status);
        self::assertTrue($this->store->writeNowRequested($opened->ref->id));
        self::assertSame([], $this->reports->statuses);
    }

    public function testAnswerNowWhileWritingChangesNothing(): void
    {
        $opened = $this->runs->start($this->user, ['question' => 'Compare A and B', 'depth' => 'auto', 'chat_id' => 11], true, null);
        $writing = new ResearchRunState($opened->ref, ResearchStatus::Writing, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($writing);

        $state = $this->runs->input($this->user, $opened->ref->id, ['type' => 'answer_now']);

        self::assertSame(ResearchStatus::Writing, $state->status);
        self::assertFalse($this->store->writeNowRequested($opened->ref->id));
        self::assertFalse($this->store->stopRequested($opened->ref->id));
    }

    public function testAFollowUpKeepsTheParent(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);
        $parent = $this->store->load($started->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $parent);
        $parent->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $this->store->save($parent);

        $next = $this->runs->followUp($this->user, $started->ref->id, [
            'type' => 'deeper',
            'text' => 'Look at delivery times too.',
        ]);

        self::assertSame($started->ref->id, $next->parent);
        self::assertSame(ResearchDepth::Thorough, $next->ref->depth);
        self::assertSame(11, $next->ref->chatId);
    }

    public function testTooManyRunsAreRefused(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => ResearchConfig::MAX_ACTIVE_RUNS === $setting ? '1' : null,
        );
        $runs = new ResearchRunService($this->store, $this->bus, new ResearchEventLog($this->keys), new ResearchConfig($repo, '1'), $this->chats(), $this->clock, $this->reports);
        $runs->start($this->user, $this->body(), true, null);

        $this->expectException(ResearchRejected::class);
        $runs->start($this->user, ['question' => 'A second question about prices?', 'depth' => 'standard', 'chat_id' => 11], true, 'other-key');
    }

    public function testASavedTaskStartDoesNotAskAndRemembersTheStep(): void
    {
        $first = $this->runs->startForSavedTask($this->user, 'When do the ferries run?', 'standard', 11, 4, 'n1');
        $again = $this->runs->startForSavedTask($this->user, 'When do the ferries run?', 'standard', 11, 4, 'n1');

        self::assertSame($first->ref->id, $again->ref->id);
        self::assertSame(0, $first->budget->extras()->questions);
        self::assertSame(4, $first->savedTaskRunId);
        self::assertSame('n1', $first->savedTaskNodeId);
        self::assertCount(1, $this->bus->sent);
        $stored = $this->store->load($first->ref->id);
        self::assertInstanceOf(ResearchRunState::class, $stored);
        self::assertSame(4, $stored->savedTaskRunId);
        self::assertSame('n1', $stored->savedTaskNodeId);
    }

    public function testAClientKeyCannotTakeOverASavedTaskRun(): void
    {
        $this->expectException(ResearchRejected::class);
        $this->runs->start($this->user, $this->body(), true, 'saved-task/4/'.substr(hash('sha256', 'n1'), 0, 16));
    }

    public function testDeletingASavedTaskRunTellsTheWaitingTask(): void
    {
        $sent = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$sent): Envelope {
            $sent[] = $message;

            return new Envelope($message);
        });
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $runs = new ResearchRunService(
            $this->store,
            $this->bus,
            $this->events,
            new ResearchConfig($this->createStub(ConfigRepository::class), '1'),
            $chats,
            $this->clock,
            $this->reports,
            new ResearchSavedTaskSignal($bus, new NullLogger()),
        );
        $state = $runs->startForSavedTask($this->user, 'When do the ferries run?', 'quick', 11, 4, 'n1');

        $runs->delete($this->user, $state->ref->id);

        self::assertCount(1, $sent);
        self::assertInstanceOf(ContinueSavedTaskAfterResearch::class, $sent[0]);
        self::assertTrue($sent[0]->deleted());
        self::assertSame(4, $sent[0]->taskRunId());
    }

    public function testASavedTaskCannotStartAThoroughResearch(): void
    {
        $this->expectException(ResearchRejected::class);
        $this->runs->startForSavedTask($this->user, 'When do the ferries run?', 'thorough', 11, 4, 'n1');
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
        $chats->method('find')->willReturn($chat);

        return $chats;
    }
}

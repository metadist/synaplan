<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatLoopTools;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use App\Service\Chat\Loop\ChatRunStep;
use App\Service\Project\ProjectTurnContext;

final class ChatRunStepTest extends ChatLoopCase
{
    public function testAPlainAnswerEndsTheRunAfterOneCall(): void
    {
        $model = $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::says('Version 5.8.9 adds the Coolify guide.')));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Completed, $stored->status);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame('Version 5.8.9 adds the Coolify guide.', $stored->answer);
        self::assertCount(1, $model->calls);
        self::assertSame('system', $model->calls[0]['messages'][0]['role']);
        self::assertSame('auto', $model->calls[0]['choice']);
        self::assertSame(1, $stored->steps);
        self::assertSame(1_000, $stored->costMicro);
        self::assertSame([ChatRunEvents::STEP, ChatRunEvents::ENDED], $this->eventNames($state->runId));
        self::assertSame(['completed'], $this->synced);
    }

    public function testASearchRunsInTheNextUnitAndTheAnswerFollows(): void
    {
        $model = $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls('web_search', ['query' => 'synaplan 5.8.9']),
            ScriptedChatRunModel::says('It adds the Coolify guide.'),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame([['name' => 'web_search', 'args' => ['query' => 'synaplan 5.8.9'], 'approvalId' => null]], $this->tools->runs);
        $tool = $model->calls[1]['messages'][3];
        self::assertSame('tool', $tool['role']);
        self::assertSame('call_1', $tool['tool_call_id']);
        self::assertSame('result of web_search', $tool['content']);
        self::assertSame(['web_search', 'custom:order', ChatLoopTools::UPDATE_PLAN], $model->offered(0));
    }

    public function testTheSameReadTwiceIsAnsweredFromTheTranscript(): void
    {
        $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls('web_search', ['query' => 'a', 'n' => 1], 'call_1'),
            ScriptedChatRunModel::calls('web_search', ['n' => 1, 'query' => 'a'], 'call_2'),
            ScriptedChatRunModel::says('Done.'),
        ));
        $state = $this->open();

        $this->drain();

        self::assertCount(1, $this->tools->runs);
        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    public function testAWriteWaitsForItsApprovalAndIsNeverRunWithoutIt(): void
    {
        $model = $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order', ['item' => 'paper'])));
        $state = $this->open('Order paper.');

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Waiting, $stored->status);
        self::assertSame(ChatRunState::WAIT_APPROVAL, $stored->waitReason);
        self::assertSame(FakeChatRunToolbox::APPROVAL_ID, $stored->awaitedApproval());
        self::assertSame([], $this->bus->queued);
        self::assertCount(1, $model->calls);
        self::assertSame([['name' => 'custom:order', 'args' => ['item' => 'paper'], 'approvalId' => null]], $this->tools->runs);
        self::assertContains(ChatRunEvents::WAITING, $this->eventNames($state->runId));
        self::assertSame(['running', 'waiting_for_you'], $this->synced);

        // A redelivered unit does not run the write or call the model again.
        $this->bus->dispatch($state->runId, ChatRunStep::UNIT, $stored->expectedSeq(), 0);
        $this->drain();
        self::assertCount(1, $this->tools->runs);
        self::assertCount(1, $model->calls);
    }

    public function testAStoredRunWaitingForApprovalNeverRunsTheWriteOnItsOwn(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order')));
        $state = $this->open();
        $this->drain();
        $stored = $this->stored($state->runId);

        // Even when a unit is forced through, an undecided approval keeps the run waiting.
        $stored->resume();
        self::assertNull($this->step->run($stored, $this->claim($stored)));
        self::assertSame(ChatRunStatus::Waiting, $stored->status);
        self::assertCount(1, $this->tools->runs);
    }

    public function testAMovedOutChatNoLongerOffersTheProjectSources(): void
    {
        $this->world->now = $this->scope(new ProjectTurnContext('project-key', 'Launch', 'Answer in short sentences.'));
        $model = $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls('web_search', ['query' => 'x']),
            ScriptedChatRunModel::says('Done.'),
        ));
        $state = $this->open();
        $unit = new \App\Service\Run\RunUnit($this->store->runs(), $this->handler, $this->bus);
        $first = array_shift($this->bus->queued);
        $unit->handle($first['runId'], $first['unit'], $first['seq']);

        self::assertContains('knowledge_search', $model->offered(0));
        self::assertStringContainsString('Answer in short sentences.', (string) $model->calls[0]['messages'][0]['content']);

        $this->world->now = $this->scope();
        $this->drain();

        self::assertNotContains('knowledge_search', $model->offered(1));
        self::assertStringNotContainsString('Answer in short sentences.', (string) $model->calls[1]['messages'][0]['content']);
        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    /**
     * @return iterable<string, array{ChatRunEnd, ChatRunStatus}>
     */
    public static function closedWorlds(): iterable
    {
        yield 'account deleted or suspended' => [ChatRunEnd::AccountGone, ChatRunStatus::Interrupted];
        yield 'chat deleted or not the owner\'s' => [ChatRunEnd::ChatGone, ChatRunStatus::Interrupted];
        yield 'loop switched off' => [ChatRunEnd::LoopOff, ChatRunStatus::Stopped];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedWorlds')]
    public function testARunWhoseWorldClosedEndsBeforeAnyCall(ChatRunEnd $end, ChatRunStatus $status): void
    {
        $this->world->now = $end;
        $model = $this->model(new ScriptedChatRunModel());
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame($status, $stored->status);
        self::assertSame($end, $stored->end);
        self::assertSame([], $model->calls);
        self::assertSame([], $this->tools->runs);
    }

    public function testAnEmptyBudgetStopsBeforeTheNextPaidCall(): void
    {
        $model = $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('web_search', ['query' => 'x'])));
        $state = $this->open();
        $unit = new \App\Service\Run\RunUnit($this->store->runs(), $this->handler, $this->bus);
        $first = array_shift($this->bus->queued);
        $unit->handle($first['runId'], $first['unit'], $first['seq']);

        $this->budget->left = false;
        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::BudgetUsed, $stored->status);
        self::assertCount(1, $model->calls);
        self::assertSame(2, $this->budget->checks);
    }

    public function testAStopEndsTheRunWithoutAnotherCall(): void
    {
        $model = $this->model(new ScriptedChatRunModel());
        $state = $this->open();
        $this->store->runs()->requestCancel($state->runId);

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Stopped, $stored->status);
        self::assertSame(ChatRunEnd::StoppedByUser, $stored->end);
        self::assertSame([], $model->calls);
    }

    public function testAPlanIsWorkedOffAndFinishedWithFinish(): void
    {
        $model = $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls(ChatLoopTools::UPDATE_PLAN, ['items' => [['text' => 'Search', 'state' => 'in_progress'], ['text' => 'Compare', 'state' => 'pending']]]),
            ScriptedChatRunModel::says('Searching now.'),
            ScriptedChatRunModel::calls(ChatLoopTools::UPDATE_PLAN, ['items' => [['text' => 'Search', 'state' => 'done'], ['text' => 'Compare', 'state' => 'done']]]),
            ScriptedChatRunModel::says('All done.'),
            ScriptedChatRunModel::calls(ChatLoopTools::FINISH, ['result' => 'Here is the comparison.', 'outcome' => 'done']),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Finished, $stored->end);
        self::assertSame('Here is the comparison.', $stored->answer);
        self::assertSame([ChatLoopTools::FINISH], $model->offered(4));
        self::assertSame('required', $model->calls[4]['choice']);
        self::assertContains(ChatRunEvents::PLAN, $this->eventNames($state->runId));
    }

    public function testARefusedFinishFallsBackToJson(): void
    {
        $model = $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls(ChatLoopTools::UPDATE_PLAN, ['items' => [['text' => 'Search', 'state' => 'done']]]),
            ScriptedChatRunModel::says('Done.'),
            new ChatRunModelFailed('tool_choice required is not supported'),
            new ChatRunReply("```json\n{\"result\": \"The answer.\", \"outcome\": \"done\"}\n```"),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Finished, $stored->end);
        self::assertSame('The answer.', $stored->answer);
        self::assertSame([], $model->calls[3]['tools']);
        self::assertNull($model->calls[3]['choice']);
    }

    public function testBrokenJsonEndsWithTheLastTextNotTheJson(): void
    {
        $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls(ChatLoopTools::UPDATE_PLAN, ['items' => [['text' => 'Search', 'state' => 'done']]]),
            ScriptedChatRunModel::says('The short answer is yes.'),
            new ChatRunModelFailed('refused'),
            new ChatRunReply('{"result": '),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::FinishFallback, $stored->end);
        self::assertSame(ChatRunStatus::Completed, $stored->status);
        self::assertSame('The short answer is yes.', $stored->answer);
    }

    public function testAModelThatNeverMovesThePlanEndsAfterFiveNudges(): void
    {
        $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls(ChatLoopTools::UPDATE_PLAN, ['items' => [['text' => 'Search', 'state' => 'pending']]]),
            ...array_fill(0, 5, ScriptedChatRunModel::says('Thinking.')),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::NoProgress, $stored->end);
        self::assertSame(6, $stored->steps);
    }

    public function testAFailedCallIsTriedOnceMoreWithoutRunningTheToolsAgain(): void
    {
        $model = $this->model(new ScriptedChatRunModel(
            ScriptedChatRunModel::calls('web_search', ['query' => 'release']),
            new ChatRunModelFailed('provider down'),
            ScriptedChatRunModel::says('It adds the guide.'),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame(0, $stored->modelFailures);
        self::assertCount(1, $this->tools->runs);
        self::assertCount(3, $model->calls);
        self::assertSame('result of web_search', $model->calls[2]['messages'][3]['content']);
    }

    public function testTwoFailedCallsInARowEndTheRunWithTheReason(): void
    {
        $this->model(new ScriptedChatRunModel(new ChatRunModelFailed('provider down'), new ChatRunModelFailed('provider down')));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Failed, $stored->status);
        self::assertSame(ChatRunEnd::ModelFailed, $stored->end);
        self::assertNull($stored->answer);
        self::assertFalse($this->store->runs()->isClaimed($state->runId, $stored->expectedSeq()));
        self::assertStringContainsString('KI-Modell', \App\Service\Chat\Loop\ChatRunDelivery::text($stored));
        self::assertSame('failed', end($this->synced));
    }

    public function testToolCallsBeyondTheStepLimitAreSkipped(): void
    {
        $calls = [];
        $limit = \App\Service\Tool\Execution\ToolLimits::MAX_TOOLS_PER_TURN;
        for ($i = 0; $i < $limit + 2; ++$i) {
            $calls[] = ['id' => 'c'.$i, 'name' => 'web_search', 'args' => ['query' => 'q'.$i]];
        }
        $this->model(new ScriptedChatRunModel(new ChatRunReply('', $calls), ScriptedChatRunModel::says('Done.')));
        $state = $this->open();

        $this->drain();

        self::assertCount($limit, $this->tools->runs);
        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    private function claim(ChatRunState $state): \App\Service\Run\RunClaim
    {
        $token = $this->store->runs()->claim($state->runId, $state->expectedSeq(), 60_000);
        self::assertNotNull($token);

        return new \App\Service\Run\RunClaim($this->store->runs(), $state->runId, $state->expectedSeq(), $token, 60_000);
    }
}

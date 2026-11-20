<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatLoopTools;
use App\Service\Chat\Loop\ChatRunCostGuard;
use App\Service\Chat\Loop\ChatRunCostLimit;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunRates;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use App\Service\Chat\Loop\ChatRunWrapUp;
use App\Service\Research\Run\ResearchModelChoice;

/**
 * The two guards of a chat run (03 §1.3 and §1.4): the cost limit, the
 * smaller of the share of the monthly budget the person chose and what is
 * left of it, and circle detection. Both end
 * the run with an answer, never with a bare stop.
 */
final class ChatRunGuardsTest extends ChatLoopCase
{
    private const CALL_COST = 20_000;

    protected function setUp(): void
    {
        parent::setUp();
        // One micro-dollar per character sent, and a full answer costs as much as a working call.
        $this->rates = new ChatRunRates(1.0, self::CALL_COST);
    }

    public function testACostLimitEndsWithAnAnswerThatSaysWhy(): void
    {
        // 0.05 per run; each working call costs 0.02.
        $this->budget->runCapMicro = 50_000;
        $model = $this->model(new ScriptedChatRunModel(
            $this->search('a'),
            $this->search('b'),
            new ChatRunReply('The release fixed the login.', [], self::CALL_COST),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::CostLimit, $stored->end);
        self::assertSame(ChatRunStatus::BudgetUsed, $stored->status);
        self::assertSame(ChatRunEnd::CostLimit, $stored->wrapUp);
        self::assertSame(ChatRunWrapUp::opening(ChatRunEnd::CostLimit, 'de')."\n\nThe release fixed the login.", $stored->answer);
        // Two working calls, then the wrap-up instead of the third.
        self::assertCount(3, $model->calls);
        self::assertSame([], $model->calls[2]['tools']);
        $last = $model->calls[2]['messages'][\count($model->calls[2]['messages']) - 1];
        self::assertSame(ChatRunWrapUp::instruction(ChatRunEnd::CostLimit), $last['content']);
        // Past the limit by at most the one wrap-up call.
        self::assertLessThanOrEqual(50_000 + self::CALL_COST, $stored->costMicro);
        self::assertContains(ChatRunEvents::WRAP_UP, $this->eventNames($state->runId));
    }

    public function testAWrapUpThatFailsStillDeliversWhatTheRunSaid(): void
    {
        $this->budget->runCapMicro = 30_000;
        $this->model(new ScriptedChatRunModel(
            new ChatRunReply('Looking it up.', [['id' => 'call_1', 'name' => 'web_search', 'args' => ['query' => 'a']]], self::CALL_COST),
            new ChatRunModelFailed('down'),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::CostLimit, $stored->end);
        self::assertSame(ChatRunWrapUp::opening(ChatRunEnd::CostLimit, 'de')."\n\nLooking it up.", $stored->answer);
    }

    public function testARunEndedAtItsCostLimitDoesNotPayTheChecker(): void
    {
        $this->checkModel = new ResearchModelChoice(91, 'other', 'other', true, true, false, null, 128_000);
        $this->budget->runCapMicro = 30_000;
        $this->tools->results['web_search'] = "[1] Changelog\nURL: https://example.com/changelog\nDescription: Release 4.2 fixed the login.\n";
        $this->model(new ScriptedChatRunModel(
            $this->search('a'),
            new ChatRunReply('Release 4.2 fixed the login for everyone [Changelog](https://example.com/changelog).', [], self::CALL_COST),
        ));
        $state = $this->open();

        $this->drain();

        self::assertSame(ChatRunEnd::CostLimit, $this->stored($state->runId)->end);
        self::assertSame([], $this->checker->users);
        self::assertContains(ChatRunEvents::CHECKED, $this->eventNames($state->runId));
    }

    public function testAModelWithoutAPriceIsNotStoppedByTheCostLimit(): void
    {
        $this->rates = null;
        $this->budget->runCapMicro = 1_000;
        $calls = [];
        for ($i = 0; $i < 10; ++$i) {
            $calls[] = new ChatRunReply('', [['id' => 'call_'.$i, 'name' => 'web_search', 'args' => ['query' => 'q'.$i]]], 0);
        }
        $this->tools->echo = true;
        $this->model(new ScriptedChatRunModel(...[...$calls, new ChatRunReply('Done.', [], 0)]));
        $state = $this->open();

        $this->drain(40);

        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    public function testHalfTheBudgetWith98PercentUsedWorksOnTheLast20CentsAndWrapsUpOnlyAtTheEnd(): void
    {
        // $10 a month, 50 % per answer ($5), but only $0.20 left.
        [$end, $calls, $cost] = $this->runOnBudget(5_000_000, 200_000);

        self::assertSame(ChatRunEnd::PayerEmpty, $end);
        // It works with the 20 cents instead of stopping at once.
        self::assertGreaterThanOrEqual(8, $calls);
        self::assertGreaterThanOrEqual(150_000, $cost);
        // And stays within what is left, give or take the one wrap-up call.
        self::assertLessThanOrEqual(200_000 + self::CALL_COST, $cost);
    }

    public function testWhenTheMonthIsAlmostUsedHalfAndATenthOfTheBudgetDoTheSame(): void
    {
        $half = $this->runOnBudget(5_000_000, 200_000);
        $tenth = $this->runOnBudget(1_000_000, 200_000);

        self::assertSame($tenth, $half);
    }

    public function testALargerShareNeverStopsARunEarlier(): void
    {
        foreach ([200_000, 900_000, 5_000_000] as $left) {
            $before = 0;
            foreach (ChatRunCostLimit::CHOICES as $percent) {
                [, $calls] = $this->runOnBudget(intdiv(10_000_000 * $percent, 100), $left, 100_000);
                self::assertGreaterThanOrEqual($before, $calls, $percent.' % with '.$left.' left');
                $before = $calls;
            }
        }
    }

    public function testNearTheLimitTheModelIsToldOnceToFinishUp(): void
    {
        $this->budget->runCapMicro = 200_000;
        $state = $this->openWorking(30);

        $this->drain(60);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::CostLimit, $stored->end);
        $told = array_keys(array_filter($stored->messages, static fn (array $m): bool => ChatRunCostGuard::NEARLY_USED === $m['content']));
        self::assertCount(1, $told);
        // Told before the end, with working steps still to come.
        self::assertLessThan(\count($stored->messages) - 2, $told[0]);
    }

    public function testAfterBeingToldToFinishUpTextWithoutAToolCallIsTheAnswer(): void
    {
        $this->budget->runCapMicro = 70_000;
        $model = $this->model(new ScriptedChatRunModel(
            new ChatRunReply('', [['id' => 'plan_1', 'name' => ChatLoopTools::UPDATE_PLAN, 'args' => ['items' => [['text' => 'Search', 'state' => 'in_progress'], ['text' => 'Compare', 'state' => 'pending']]]]], self::CALL_COST),
            $this->search('a'),
            new ChatRunReply('Here is what I have: the login was fixed.', [], self::CALL_COST),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertTrue($stored->budgetNudged);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame('Here is what I have: the login was fixed.', $stored->answer);
        // No call to go on with the plan, and none for a summary.
        self::assertCount(3, $model->calls);
    }

    public function testAnAnswerAlreadyWrittenAtTheLimitIsDeliveredWithoutAWrapUpCall(): void
    {
        $this->budget->runCapMicro = 70_000;
        $model = $this->model(new ScriptedChatRunModel(
            new ChatRunReply('', [['id' => 'plan_1', 'name' => ChatLoopTools::UPDATE_PLAN, 'args' => ['items' => [['text' => 'Search', 'state' => 'in_progress'], ['text' => 'Compare', 'state' => 'pending']]]]], self::CALL_COST),
            new ChatRunReply($this->answerText(), [], 30_000),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::CostLimit, $stored->end);
        self::assertSame(ChatRunEnd::CostLimit, $stored->wrapUp);
        self::assertSame(ChatRunWrapUp::opening(ChatRunEnd::CostLimit, 'de')."\n\n".$this->answerText(), $stored->answer);
        self::assertCount(2, $model->calls);
    }

    public function testAnAnswerWrittenWithAPlanUpdateAtTheLimitIsDeliveredToo(): void
    {
        $this->budget->runCapMicro = 70_000;
        $done = ['id' => 'plan_2', 'name' => ChatLoopTools::UPDATE_PLAN, 'args' => ['items' => [['text' => 'Search', 'state' => 'done'], ['text' => 'Compare', 'state' => 'pending']]]];
        $model = $this->model(new ScriptedChatRunModel(
            new ChatRunReply('', [['id' => 'plan_1', 'name' => ChatLoopTools::UPDATE_PLAN, 'args' => ['items' => [['text' => 'Search', 'state' => 'in_progress'], ['text' => 'Compare', 'state' => 'pending']]]]], self::CALL_COST),
            new ChatRunReply($this->answerText(), [$done], 30_000),
        ));
        $state = $this->open();

        $this->drain();

        self::assertSame(ChatRunWrapUp::opening(ChatRunEnd::CostLimit, 'de')."\n\n".$this->answerText(), $this->stored($state->runId)->answer);
        self::assertCount(2, $model->calls);
    }

    public function testAShortNoteBeforeASearchAtTheLimitStillGetsTheWrapUpCall(): void
    {
        $this->budget->runCapMicro = 70_000;
        $model = $this->model(new ScriptedChatRunModel(
            $this->search('a'),
            new ChatRunReply('Now I read the changelog.', [['id' => 'call_b', 'name' => 'web_search', 'args' => ['query' => 'b']]], 30_000),
            new ChatRunReply('The release fixed the login.', [], self::CALL_COST),
        ));
        $state = $this->open();

        $this->drain();

        self::assertSame(ChatRunWrapUp::opening(ChatRunEnd::CostLimit, 'de')."\n\nThe release fixed the login.", $this->stored($state->runId)->answer);
        self::assertCount(3, $model->calls);
    }

    public function testEachWorkingCallIsToldTheBudgetAndTheTranscriptKeepsNoAmounts(): void
    {
        $this->budget->runCapMicro = 200_000;
        $model = $this->model(new ScriptedChatRunModel($this->search('a'), $this->search('b'), ScriptedChatRunModel::says('Done.')));
        $state = $this->open();

        $this->drain();

        // The first call has spent nothing yet; from the second on the line is there.
        foreach ($model->calls[0]['messages'] as $message) {
            self::assertStringNotContainsString('Budget for this answer', (string) $message['content']);
        }
        $line = $model->calls[1]['messages'][\count($model->calls[1]['messages']) - 1];
        self::assertSame(['role' => 'user', 'content' => 'Budget for this answer: $0.20, used $0.02 ($0.18 left). Plan your remaining steps to finish within it.'], $line);
        foreach ($this->stored($state->runId)->messages as $message) {
            self::assertStringNotContainsString('Budget for this answer', (string) $message['content']);
        }
    }

    public function testAPlanWithoutAMonthlyBudgetGetsNoBudgetLine(): void
    {
        $model = $this->model(new ScriptedChatRunModel($this->search('a'), ScriptedChatRunModel::says('Done.')));
        $this->open();

        $this->drain();

        foreach ($model->calls[1]['messages'] as $message) {
            self::assertStringNotContainsString('Budget for this answer', (string) $message['content']);
        }
    }

    public function testASearchThatAlwaysSaysTheSameIsNudgedOnceThenWrappedUp(): void
    {
        $this->tools->results['web_search'] = 'The same three links again.';
        $calls = [];
        for ($i = 0; $i < 13; ++$i) {
            $calls[] = $this->search('query '.$i);
        }
        $model = $this->model(new ScriptedChatRunModel(...[...$calls, ScriptedChatRunModel::says('Here is what the search gave.')]));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Circling, $stored->end);
        self::assertSame(ChatRunStatus::Completed, $stored->status);
        self::assertStringStartsWith(ChatRunWrapUp::opening(ChatRunEnd::Circling, 'de'), (string) $stored->answer);
        self::assertSame(1, $this->times($state->runId, ChatRunEvents::CIRCLING));
        self::assertSame(1, $this->nudges($stored));
        // Six steps without anything new, the nudge, six more, then the wrap-up.
        self::assertCount(14, $model->calls);
    }

    public function testAToolThatAlwaysFailsIsNudgedOnceThenWrappedUp(): void
    {
        $this->tools->fails = ['web_search'];
        $calls = [];
        for ($i = 0; $i < 10; ++$i) {
            $calls[] = $this->search('query '.$i);
        }
        $model = $this->model(new ScriptedChatRunModel(...[...$calls, ScriptedChatRunModel::says('The search does not work right now.')]));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Circling, $stored->end);
        self::assertSame(1, $this->nudges($stored));
        self::assertStringContainsString('tool calls failed', $this->nudgeText($stored));
        self::assertCount(11, $model->calls);
    }

    public function testTheSameCallThreeTimesIsNamedInTheNudge(): void
    {
        $model = $this->model(new ScriptedChatRunModel(
            $this->search('Release Notes'),
            $this->search('release  notes '),
            $this->search('release notes'),
            ScriptedChatRunModel::says('The notes say the login was fixed.'),
        ));
        $state = $this->open();

        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame('The notes say the login was fixed.', $stored->answer);
        self::assertStringContainsString('called web_search with the same input 3 times', $this->nudgeText($stored));
        self::assertCount(4, $model->calls);
    }

    public function testARunThatKeepsFindingSomethingNewIsNeverStopped(): void
    {
        $this->tools->echo = true;
        $calls = [];
        for ($i = 0; $i < 200; ++$i) {
            $calls[] = new ChatRunReply('', [['id' => 'call_'.$i, 'name' => 'web_search', 'args' => ['query' => 'page '.$i]]], 1_000);
        }
        $model = $this->model(new ScriptedChatRunModel(...[...$calls, ScriptedChatRunModel::says('All 200 pages read.')]));
        $state = $this->open();

        $this->drain(500);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame(201, $stored->steps);
        self::assertCount(201, $model->calls);
        self::assertSame(0, $this->times($state->runId, ChatRunEvents::CIRCLING));
    }

    private function search(string $query, int $cost = self::CALL_COST): ChatRunReply
    {
        return new ChatRunReply('', [['id' => 'call_'.md5($query), 'name' => 'web_search', 'args' => ['query' => $query]]], $cost);
    }

    private function answerText(): string
    {
        return trim('Release 4.2 fixed the login for everyone. '.str_repeat('It also changed how sessions expire after a password reset. ', 10));
    }

    /** A run of searches that each find something new, then an answer. */
    private function openWorking(int $searches, int $cost = self::CALL_COST): ChatRunState
    {
        $this->tools->echo = true;
        $calls = [];
        for ($i = 0; $i < $searches; ++$i) {
            $calls[] = $this->search('page '.$i, $cost);
        }
        $this->model(new ScriptedChatRunModel(...[...$calls, new ChatRunReply('Here is what I found.', [], $cost)]));

        return $this->open();
    }

    /**
     * One run on a share and what is left of the month, the left amount
     * shrinking with what the run spends.
     *
     * @return array{0: ChatRunEnd|null, 1: int, 2: int} how it ended, its model calls and its cost
     */
    private function runOnBudget(int $runCap, int $left, int $cost = self::CALL_COST): array
    {
        $this->budget->runCapMicro = $runCap;
        $this->budget->leftMicro = $left;
        $state = $this->openWorking(intdiv(min($runCap, $left), $cost) + 5, $cost);
        $this->budget->spent = fn (): int => $this->stored($state->runId)->costMicro;

        $this->drain(1_000);
        $stored = $this->stored($state->runId);

        return [$stored->end, $stored->steps, $stored->costMicro];
    }

    private function times(string $runId, string $event): int
    {
        return \count(array_filter($this->eventNames($runId), static fn (string $name): bool => $name === $event));
    }

    private function nudges(ChatRunState $state): int
    {
        return \count(array_filter($state->messages, static fn (array $m): bool => 'user' === $m['role'] && str_starts_with((string) $m['content'], 'You are going in circles')));
    }

    private function nudgeText(ChatRunState $state): string
    {
        foreach ($state->messages as $message) {
            if ('user' === $message['role'] && str_starts_with((string) $message['content'], 'You are going in circles')) {
                return (string) $message['content'];
            }
        }

        return '';
    }
}

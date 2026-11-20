<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use App\Service\Chat\Loop\ChatRunWrapUp;
use App\Service\Research\Run\ResearchModelChoice;

/**
 * The two guards of a chat run (03 §1.3 and §1.4): the cost limit, a share
 * of the monthly budget the person chose, and circle detection. Both end
 * the run with an answer, never with a bare stop.
 */
final class ChatRunGuardsTest extends ChatLoopCase
{
    private const CALL_COST = 20_000;

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

    private function search(string $query): ChatRunReply
    {
        return new ChatRunReply('', [['id' => 'call_'.md5($query), 'name' => 'web_search', 'args' => ['query' => $query]]], self::CALL_COST);
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

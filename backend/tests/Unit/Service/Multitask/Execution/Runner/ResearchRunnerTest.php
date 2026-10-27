<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeStatus;
use App\Service\Multitask\Execution\Runner\ResearchRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchOutcomeText;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchTaskHandover;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResearchRunnerTest extends TestCase
{
    public function testASavedTaskStepPausesWithoutSearchingInsideTheTaskWorker(): void
    {
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'When do the ferries run?', 11),
            ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard)),
        );
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects(self::once())->method('startForSavedTask')->with(
            self::isInstanceOf(User::class),
            'When do the ferries run?',
            'standard',
            11,
            4,
            'n1',
        )->willReturn($state);

        $result = $this->runner($runs, true, true)->run(
            new TaskNode('n1', Capability::Research, [], [], ['depth' => 'standard']),
            $this->context('When do the ferries run?', 4),
        );

        self::assertSame(NodeStatus::WaitingApproval, $result->status);
        self::assertSame($state->ref->id, $result->metadata['research']['run_id']);
    }

    public function testTheReportIsTheStepResultAndDoesNotStartAnotherRun(): void
    {
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects(self::never())->method('startForSavedTask');

        $result = $this->runner($runs, true, true)->run(new TaskNode('n1', Capability::Research, [], [], [
            'research_report' => 'The last boat is at 18:00.',
            'research_files' => [[
                'path' => '/api/v1/files/uploads/research.md',
                'type' => 'text/markdown',
                'local_path' => 'research.md',
            ]],
        ]), $this->context('ignored', 4));

        self::assertSame(NodeStatus::Done, $result->status);
        self::assertSame('The last boat is at 18:00.', $result->text);
        self::assertSame('research.md', $result->files[0]['local_path']);
    }

    public function testAFailedResearchFailsTheStep(): void
    {
        $result = $this->runner($this->createStub(ResearchRunService::class), true, true)->run(
            new TaskNode('n1', Capability::Research, [], [], ['research_failed' => 'Nothing was found.']),
            $this->context('ignored', 4),
        );

        self::assertSame(NodeStatus::Failed, $result->status);
        self::assertSame('Nothing was found.', $result->error);
    }

    public function testTheStepStaysHiddenAndIdleWhenTheTaskFlagIsOff(): void
    {
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects(self::never())->method('startForSavedTask');

        $result = $this->runner($runs, false, true)->run(
            new TaskNode('n1', Capability::Research, [], [], []),
            $this->context('When do the ferries run?', 4),
        );

        self::assertSame(NodeStatus::Failed, $result->status);
        self::assertSame('Research is turned off for saved tasks, so this step did not start.', $result->error);
    }

    public function testAThoroughDepthIsRefused(): void
    {
        $runs = $this->createStub(ResearchRunService::class);
        $runs->method('startForSavedTask')->willThrowException(new ResearchRejected(
            400,
            'invalid',
            'depth',
            'A saved task can only run a quick or a standard research.',
        ));

        $result = $this->runner($runs, true, true)->run(
            new TaskNode('n1', Capability::Research, [], [], ['depth' => 'thorough']),
            $this->context('When do the ferries run?', 4),
        );

        self::assertSame(NodeStatus::Failed, $result->status);
        self::assertSame('A saved task can only run a quick or a standard research.', $result->error);
    }

    public function testAChatTurnDoesNotStartResearch(): void
    {
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects(self::never())->method('startForSavedTask');

        $result = $this->runner($runs, true, true)->run(
            new TaskNode('n1', Capability::Research, [], [], []),
            $this->context('When do the ferries run?', null),
        );

        self::assertSame('This step runs inside a saved task. Research did not start.', $result->error);
    }

    public function testARunThatAlreadyFinishedDoesNotPauseTheTask(): void
    {
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'When do the ferries run?', 11),
            ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard)),
        );
        $state->ledger->setReport('The last boat is at 18:00.');
        $state->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects(self::once())->method('startForSavedTask')->willReturn($state);

        $result = $this->runner($runs, true, true)->run(
            new TaskNode('n1', Capability::Research, [], [], ['depth' => 'standard']),
            $this->context('When do the ferries run?', 4),
        );

        self::assertSame(NodeStatus::Done, $result->status);
        self::assertSame('The last boat is at 18:00.', $result->text);
    }

    private function runner(ResearchRunService $runs, bool $taskFlag, bool $engine): ResearchRunner
    {
        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('getValue')->willReturn($taskFlag ? '1' : null);
        $users = $this->createStub(UserRepository::class);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users->method('find')->willReturn($user);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ResearchRunner(
            $runs,
            $users,
            new ResearchConfig($this->createStub(ConfigRepository::class), $engine ? '1' : '0'),
            new MultitaskRoutingConfig($configs),
            new NullLogger(),
            new ResearchTaskHandover(new ResearchOutcomeText($translator), $users),
        );
    }

    private function context(string $text, ?int $taskRunId): NodeContext
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(11);
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getChat')->willReturn($chat);
        $options = null !== $taskRunId ? ['saved_task_run_id' => $taskRunId] : [];

        return new NodeContext($message, [], 7, [], $options);
    }
}

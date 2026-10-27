<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Message\ContinueSavedTaskAfterResearch;
use App\Repository\UserRepository;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchOutcomeText;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchSavedTaskResume;
use App\Service\Research\Run\ResearchStatus;
use App\Service\Research\Run\ResearchTaskHandover;
use App\Service\SavedTask\SavedTaskResearchContinuation;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResearchSavedTaskResumeTest extends ResearchUnitCase
{
    private const REPORT_FILE = [
        'path' => '/api/v1/files/uploads/research.md',
        'type' => 'text/markdown',
        'local_path' => 'research.md',
    ];

    public function testAFinishedReportContinuesTheSavedTaskWithItsFile(): void
    {
        $state = $this->finished(ResearchOutcome::COMPLETE, 'The last boat is at 18:00.');
        $tasks = $this->createMock(SavedTaskResearchContinuation::class);
        $tasks->expects(self::once())->method('complete')->with(9, 'step_1', 7, [
            'research_report' => 'The last boat is at 18:00.',
            'research_files' => [self::REPORT_FILE],
        ])->willReturn(new SavedTaskRun(3, 'schedule'));

        $this->resume($tasks)->continue($this->message($state));
    }

    public function testAFailedRunFailsTheStepWithItsSentence(): void
    {
        $state = $this->finished(ResearchOutcome::FAILED_NOTHING_FOUND, '');
        $tasks = $this->createMock(SavedTaskResearchContinuation::class);
        $tasks->expects(self::once())->method('complete')->with(
            9,
            'step_1',
            7,
            self::callback(static fn (array $args): bool => is_string($args['research_failed'] ?? null) && '' !== $args['research_failed']),
        )->willReturn(new SavedTaskRun(3, 'schedule'));

        $this->resume($tasks)->continue($this->message($state));
    }

    public function testADeletedRunFailsTheStepInsteadOfLeavingItWaiting(): void
    {
        $tasks = $this->createMock(SavedTaskResearchContinuation::class);
        $tasks->expects(self::once())->method('complete')->with(
            9,
            'step_1',
            7,
            ['research_failed' => 'The research was deleted, so the next step did not run.'],
        )->willReturn(new SavedTaskRun(3, 'schedule'));

        $this->resume($tasks)->continue(new ContinueSavedTaskAfterResearch('gone', 7, 9, 'step_1', 0, null, true));
    }

    public function testAFinishWhileTheTaskIsStillStartingIsTriedAgain(): void
    {
        $state = $this->finished(ResearchOutcome::COMPLETE, 'The last boat is at 18:00.');
        $tasks = $this->createStub(SavedTaskResearchContinuation::class);
        $tasks->method('complete')->willReturn(null);
        $tasks->method('isActive')->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(
            self::callback(static fn (ContinueSavedTaskAfterResearch $message): bool => 1 === $message->attempt()
                && $message->runId() === $state->ref->id
                && self::REPORT_FILE === $message->reportFile()),
            self::callback(static fn (array $stamps): bool => ($stamps[0] ?? null) instanceof DelayStamp),
        )->willReturn(new Envelope(new ContinueSavedTaskAfterResearch($state->ref->id, 7, 9, 'step_1', 1)));

        $this->resume($tasks, $bus)->continue($this->message($state));
    }

    public function testAnErrorIsTriedAgainInsteadOfDropped(): void
    {
        $state = $this->finished(ResearchOutcome::COMPLETE, 'The last boat is at 18:00.');
        $tasks = $this->createStub(SavedTaskResearchContinuation::class);
        $tasks->method('complete')->willThrowException(new \RuntimeException('Database went away'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->willReturn(new Envelope(new ContinueSavedTaskAfterResearch($state->ref->id, 7, 9, 'step_1', 1)));

        $this->resume($tasks, $bus)->continue($this->message($state));
    }

    public function testWhenTheRetriesRunOutTheWaitingStepFails(): void
    {
        $state = $this->finished(ResearchOutcome::COMPLETE, 'The last boat is at 18:00.');
        $tasks = $this->createMock(SavedTaskResearchContinuation::class);
        $tasks->method('complete')->willReturn(null);
        $tasks->method('isActive')->willReturn(true);
        $tasks->expects(self::once())->method('fail')->with(9, 'step_1', self::stringContains('The research result is in the chat.'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->resume($tasks, $bus)->continue(new ContinueSavedTaskAfterResearch($state->ref->id, 7, 9, 'step_1', 180));
    }

    private function resume(SavedTaskResearchContinuation $tasks, ?MessageBusInterface $bus = null): ResearchSavedTaskResume
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $user = $this->createStub(User::class);
        $user->method('getLocale')->willReturn('en');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        return new ResearchSavedTaskResume(
            $tasks,
            new ResearchTaskHandover(new ResearchOutcomeText($translator), $users),
            $this->store,
            $bus ?? $this->createStub(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    private function finished(string $code, string $report): ResearchRunState
    {
        $state = $this->openRun();
        $state->savedTaskRunId = 9;
        $state->savedTaskNodeId = 'step_1';
        if ('' !== $report) {
            $state->ledger->setReport($report);
        }
        $state->finish(ResearchOutcome::COMPLETE === $code ? ResearchStatus::Complete : ResearchStatus::Failed, new ResearchOutcome($code));
        $this->store->save($state);

        return $state;
    }

    private function message(ResearchRunState $state): ContinueSavedTaskAfterResearch
    {
        return new ContinueSavedTaskAfterResearch($state->ref->id, $state->ref->userId, 9, 'step_1', 0, self::REPORT_FILE);
    }
}

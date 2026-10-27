<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchOutcomeText;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchSavedTaskResume;
use App\Service\Research\Run\ResearchStatus;
use App\Service\SavedTask\SavedTaskResumeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResearchSavedTaskResumeTest extends TestCase
{
    public function testAFinishedReportContinuesTheSavedTask(): void
    {
        $state = $this->state(ResearchOutcome::COMPLETE);
        $state->ledger->setReport('The last boat is at 18:00.');
        $state->reportFile = [
            'path' => '/api/v1/files/uploads/research.md',
            'type' => 'text/markdown',
            'local_path' => 'research.md',
        ];
        $tasks = $this->createMock(SavedTaskResumeService::class);
        $tasks->expects(self::once())->method('completeWaitingResearch')->with(
            9,
            'step_1',
            [
                'research_report' => 'The last boat is at 18:00.',
                'research_files' => [$state->reportFile],
            ],
        );

        $this->resume($tasks)->after($state);
    }

    public function testAFailedRunFailsTheStepWithItsSentence(): void
    {
        $state = $this->state(ResearchOutcome::FAILED_NOTHING_FOUND);
        $tasks = $this->createMock(SavedTaskResumeService::class);
        $tasks->expects(self::once())->method('completeWaitingResearch')->with(
            9,
            'step_1',
            self::callback(static fn (array $args): bool => is_string($args['research_failed'] ?? null) && '' !== $args['research_failed']),
        );

        $this->resume($tasks)->after($state);
    }

    public function testARunThatIsNotASavedTaskDoesNotResumeOne(): void
    {
        $state = $this->state(ResearchOutcome::COMPLETE);
        $state->savedTaskRunId = null;
        $state->ledger->setReport('The last boat is at 18:00.');
        $tasks = $this->createMock(SavedTaskResumeService::class);
        $tasks->expects(self::never())->method('completeWaitingResearch');

        $this->resume($tasks)->after($state);
    }

    private function resume(SavedTaskResumeService $tasks): ResearchSavedTaskResume
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $user = $this->createStub(User::class);
        $user->method('getLocale')->willReturn('en');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);

        return new ResearchSavedTaskResume($tasks, new ResearchOutcomeText($translator), $users, new NullLogger());
    }

    private function state(string $code): ResearchRunState
    {
        $state = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'When do the ferries run?', 11),
            ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard)),
        );
        $state->savedTaskRunId = 9;
        $state->savedTaskNodeId = 'step_1';
        $status = ResearchOutcome::COMPLETE === $code ? ResearchStatus::Complete : ResearchStatus::Failed;
        $state->finish($status, new ResearchOutcome($code));

        return $state;
    }
}

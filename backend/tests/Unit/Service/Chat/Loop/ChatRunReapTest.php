<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Approval;
use App\Message\RunChatUnit;
use App\Repository\ApprovalRepository;
use App\Service\Chat\Loop\ChatRunApprovals;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunReapPolicy;
use App\Service\Chat\Loop\ChatRunResume;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use App\Service\Chat\Loop\ChatRunStep;
use App\Service\Chat\Loop\ChatRunUnitHandler;
use App\Service\Chat\Loop\MessengerChatRunUnitBus;
use App\Service\Run\RunReaper;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChatRunReapTest extends ChatLoopCase
{
    private ?Approval $approval = null;

    /** @var list<RunChatUnit> */
    private array $messages = [];

    /** @var list<int> the approval cards that were taken away */
    private array $expired = [];

    protected function approvalRows(): ApprovalRepository
    {
        return $this->rows();
    }

    public function testAUnitLostTwiceEndsTheRunAsInterrupted(): void
    {
        // An empty script throws on the call, like a worker that died in it.
        $model = $this->model(new ScriptedChatRunModel());
        $state = $this->open();
        $this->drainFailing();

        $this->clock->modify('+'.(ChatRunUnitHandler::LEASE_MS / 1000 + 1).' seconds');
        self::assertSame(1, $this->reaper()->reap()['redispatched']);
        $this->drainFailing();

        $this->clock->modify('+'.(ChatRunUnitHandler::LEASE_MS / 1000 + 1).' seconds');
        self::assertSame(1, $this->reaper()->reap()['interrupted']);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Interrupted, $stored->status);
        self::assertSame(ChatRunEnd::WorkerLost, $stored->end);
        self::assertCount(2, $model->calls);
        self::assertSame('interrupted', end($this->synced));
    }

    public function testAnApprovedWriteRunsWithItsApprovalAndTheRunAnswers(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order', ['item' => 'paper']), ScriptedChatRunModel::says('Ordered.')));
        $state = $this->waitingRun();
        $this->approval?->markApproved(self::USER_ID);

        self::assertSame(1, $this->reaper()->reap()['waited']);
        $this->forwardMessages();
        $this->drain();

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertSame(FakeChatRunToolbox::APPROVAL_ID, $this->tools->runs[1]['approvalId']);
        self::assertCount(2, $this->tools->runs);
    }

    public function testADeclinedWriteIsNotRunAndTheModelIsTold(): void
    {
        $model = $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order'), ScriptedChatRunModel::says('I did not order it.')));
        $state = $this->waitingRun();
        $this->approval?->markRejected(self::USER_ID);

        $this->reaper()->reap();
        $this->forwardMessages();
        $this->drain();

        self::assertCount(1, $this->tools->runs);
        self::assertSame(ChatRunStep::DECLINED, $model->calls[1]['messages'][3]['content']);
        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    public function testAnUndecidedApprovalWaitsUntilTheLimitThenCountsAsDeclined(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order'), ScriptedChatRunModel::says('Not ordered.')));
        $state = $this->waitingRun();

        self::assertSame(0, $this->reaper()->reap()['waited']);
        self::assertSame(ChatRunStatus::Waiting, $this->stored($state->runId)->status);

        $this->clock->modify('+'.ChatRunReapPolicy::MAX_WAIT_SECONDS.' seconds');
        self::assertSame(1, $this->reaper()->reap()['waited']);
        self::assertSame([FakeChatRunToolbox::APPROVAL_ID], $this->expired);
        $this->forwardMessages();
        $this->drain();

        self::assertCount(1, $this->tools->runs);
        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
    }

    public function testAnApprovalForAnotherRunDoesNotResumeThisOne(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order')));
        $state = $this->waitingRun();

        self::assertFalse($this->resume()->continue($state, FakeChatRunToolbox::APPROVAL_ID + 1, ChatRunState::DECISION_APPROVED));
        self::assertSame(ChatRunStatus::Waiting, $this->stored($state->runId)->status);
        self::assertSame([], $this->messages);
    }

    public function testAResumeFromTheApprovalRowNeedsTheRunOwner(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order')));
        $state = $this->waitingRun();
        $this->approval = new Approval(self::USER_ID + 1, ChatRunApprovals::requestedBy($state->runId), 'custom:order', 'write', time() + 3600);
        $this->approval->markApproved(self::USER_ID + 1);

        $this->resume()->decided(FakeChatRunToolbox::APPROVAL_ID);

        self::assertSame(ChatRunStatus::Waiting, $this->stored($state->runId)->status);
        self::assertSame([], $this->messages);
    }

    public function testAStoppedWaitingRunEndsAsStopped(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::calls('custom:order')));
        $state = $this->waitingRun();
        $this->store->runs()->requestCancel($state->runId);

        self::assertSame(1, $this->reaper()->reap()['waited']);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Stopped, $stored->status);
        self::assertSame(ChatRunEnd::StoppedByUser, $stored->end);
        self::assertCount(1, $this->tools->runs);
        self::assertSame([FakeChatRunToolbox::APPROVAL_ID], $this->expired);
    }

    private function waitingRun(): ChatRunState
    {
        $state = $this->open('Order paper.');
        $this->drain();
        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunStatus::Waiting, $stored->status);
        $this->approval = new Approval(self::USER_ID, ChatRunApprovals::requestedBy($state->runId), 'custom:order', 'write', time() + 3600);
        (new \ReflectionProperty(Approval::class, 'id'))->setValue($this->approval, FakeChatRunToolbox::APPROVAL_ID);

        return $stored;
    }

    private function drainFailing(): void
    {
        try {
            $this->drain();
        } catch (\LogicException) {
        }
    }

    private function forwardMessages(): void
    {
        foreach ($this->messages as $message) {
            $this->bus->dispatch($message->runId(), $message->unit(), $message->seq(), 0);
        }
        $this->messages = [];
    }

    private function reaper(): RunReaper
    {
        $repo = $this->rows();
        $policy = new ChatRunReapPolicy($this->store, $repo, new ChatRunApprovals($repo, $this->clock), $this->resume($repo), $this->step, $this->handler);

        return new RunReaper($this->store->runs(), $policy, $this->bus, $this->clock, new NullLogger(), ChatRunUnitHandler::LEASE_MS);
    }

    private function rows(): ApprovalRepository
    {
        $repo = $this->createStub(ApprovalRepository::class);
        $repo->method('find')->willReturnCallback(fn (): ?Approval => $this->approval);
        $repo->method('findLatestForRequestedBy')->willReturnCallback(fn (): ?Approval => $this->approval);
        $repo->method('expireIfPending')->willReturnCallback(function (int $id): bool {
            $this->expired[] = $id;
            $this->approval?->markExpired();

            return true;
        });

        return $repo;
    }

    private function resume(?ApprovalRepository $repo = null): ChatRunResume
    {
        if (null === $repo) {
            $repo = $this->rows();
        }
        $messenger = $this->createStub(MessageBusInterface::class);
        $messenger->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($message instanceof RunChatUnit) {
                $this->messages[] = $message;
            }

            return new Envelope($message);
        });

        return new ChatRunResume($repo, new ChatRunApprovals($repo, $this->clock), $this->store, $this->handler, new MessengerChatRunUnitBus($messenger), $this->events);
    }
}

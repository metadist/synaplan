<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Approval;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class StepApprovalGateTest extends TestCase
{
    public function testPausesWhenPolicyRequiresApproval(): void
    {
        $approval = $this->createMock(Approval::class);
        $approval->method('getId')->willReturn(42);
        $approval->method('getTool')->willReturn('skill:email_me');
        $approval->method('getPreview')->willReturn('Send mail');
        $approval->method('getExpiresAt')->willReturn(1_800_000_000);
        $approval->method('getSideEffect')->willReturn('write');

        $executionGate = $this->createMock(ToolExecutionGate::class);
        $executionGate->expects(self::once())
            ->method('inspect')
            ->with(
                7,
                'skill:email_me',
                ['to' => 'alice@example.com'],
                self::isInstanceOf(User::class),
                PolicyContext::Unattended,
                'task_run:9:n3',
            )
            ->willReturn([
                'outcome' => PolicyOutcome::Approve,
                'descriptor' => $this->descriptor(),
                'approval' => $approval,
                'refusal' => null,
            ]);

        $result = $this->gate($executionGate)->consult(
            $this->context(['saved_task' => true, 'saved_task_run_id' => 9]),
            new TaskNode('n3', Capability::EmailMe),
            'skill:email_me',
            ['to' => 'alice@example.com'],
        );

        self::assertNotNull($result);
        self::assertTrue($result->isWaitingApproval());
        self::assertSame(42, $result->metadata['approval_id']);
        self::assertSame('skill:email_me', $result->metadata['tool']);
    }

    public function testRunsWhenPolicyIsAuto(): void
    {
        $executionGate = $this->createMock(ToolExecutionGate::class);
        $executionGate->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => $this->descriptor(),
            'approval' => null,
            'refusal' => null,
        ]);

        self::assertNull($this->gate($executionGate)->consult(
            $this->context(),
            new TaskNode('n3', Capability::EmailMe),
            'skill:email_me',
            [],
        ));
    }

    public function testFailsWhenPolicyBlocks(): void
    {
        $executionGate = $this->createMock(ToolExecutionGate::class);
        $executionGate->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Block,
            'descriptor' => $this->descriptor(),
            'approval' => null,
            'refusal' => ToolExecutionGate::NODE_BLOCK_REFUSAL,
        ]);

        $result = $this->gate($executionGate)->consult(
            $this->context(),
            new TaskNode('n3', Capability::EmailMe, [], [], ['approval' => 'block']),
            'skill:email_me',
            [],
        );

        self::assertNotNull($result);
        self::assertFalse($result->isSuccessful());
        self::assertSame(ToolExecutionGate::NODE_BLOCK_REFUSAL, $result->error);
    }

    public function testSkipsWhenTheNodeWasAlreadyApproved(): void
    {
        $executionGate = $this->createMock(ToolExecutionGate::class);
        $executionGate->expects(self::never())->method('inspect');

        $ctx = $this->context();
        $ctx->markApproved('n3');

        self::assertNull($this->gate($executionGate)->consult(
            $ctx,
            new TaskNode('n3', Capability::EmailMe),
            'skill:email_me',
            [],
        ));
    }

    public function testFailsWhenTheToolIsNotRegistered(): void
    {
        $executionGate = $this->createMock(ToolExecutionGate::class);
        $executionGate->method('inspect')->willThrowException(new ToolNotRegisteredException('skill:email_me'));

        $result = $this->gate($executionGate)->consult(
            $this->context(),
            new TaskNode('n3', Capability::EmailMe),
            'skill:email_me',
            [],
        );

        self::assertNotNull($result);
        self::assertFalse($result->isSuccessful());
        self::assertNotSame('', (string) $result->error);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function context(array $options = []): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(11);
        $message->method('getUserId')->willReturn(7);

        return new NodeContext($message, [], 7, [], $options);
    }

    private function gate(ToolExecutionGate $executionGate): StepApprovalGate
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($this->createStub(User::class));

        return new StepApprovalGate($executionGate, $users);
    }

    private function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            'skill:email_me',
            'email_me',
            '',
            [],
            \App\Service\Tool\SideEffect::Write,
            ToolSource::Skill,
            0,
        );
    }
}

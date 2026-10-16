<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Approval;
use App\Entity\CustomTool;
use App\Entity\User;
use App\Message\ResumeApprovalCommand;
use App\MessageHandler\ResumeApprovalCommandHandler;
use App\Repository\ApprovalRepository;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Tool\ApprovalRealtimeNotifier;
use App\Service\Tool\ChatApprovalContinuationService;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResumeApprovalCommandHandlerTest extends TestCase
{
    public function testCustomChatApprovalContinuesTheThread(): void
    {
        [$handler, $mocks] = $this->handler();
        $approval = $this->approval('chat:11');
        $mocks['approvals']->expects(self::any())->method('find')->with(42)->willReturn($approval);
        $mocks['registry']->expects(self::any())->method('get')->with(7, 'custom:acme')->willReturn($this->descriptor(ToolSource::Custom));
        $tool = $this->createStub(CustomTool::class);
        $tool->method('getId')->willReturn(3);
        $mocks['customTools']->expects(self::any())->method('find')->with(9)->willReturn($tool);
        $mocks['httpExecutor']->method('execute')->willReturn(['status' => 200, 'summary' => 'order id 42']);

        $approval->expects(self::once())->method('markExecuted')->with('custom:3:200');
        $mocks['continuation']->expects(self::once())->method('continueChat')->with(
            $approval,
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'order id 42'
        );
        $mocks['notifier']->expects(self::once())->method('executed')->with($approval, 'order id 42');

        $handler->__invoke(new ResumeApprovalCommand(42));
    }

    public function testFailedExecutionContinuesTheThreadAsFailed(): void
    {
        [$handler, $mocks] = $this->handler();
        $approval = $this->approval('chat:11');
        $mocks['approvals']->expects(self::any())->method('find')->with(42)->willReturn($approval);
        $mocks['registry']->method('get')->willReturn(null);

        $approval->expects(self::once())->method('markFailed')->with('tool_not_registered');
        $mocks['continuation']->expects(self::once())->method('continueChat')->with(
            $approval,
            ChatApprovalContinuationService::OUTCOME_FAILED,
            null
        );

        $handler->__invoke(new ResumeApprovalCommand(42));
    }

    public function testComputeChatApprovalFailsHonestlyInsteadOfFakeExecuted(): void
    {
        [$handler, $mocks] = $this->handler();
        $approval = $this->approval('chat:11', 'code_run');
        $mocks['approvals']->expects(self::any())->method('find')->with(42)->willReturn($approval);
        $mocks['registry']->expects(self::any())->method('get')->with(7, 'code_run')->willReturn($this->descriptor(ToolSource::Compute));

        $approval->expects(self::never())->method('markExecuted');
        $approval->expects(self::once())->method('markFailed')->with('chat_execution_unsupported');
        $mocks['continuation']->expects(self::once())->method('continueChat')->with(
            $approval,
            ChatApprovalContinuationService::OUTCOME_UNSUPPORTED,
            null
        );
        $mocks['notifier']->expects(self::once())->method('executed')->with($approval, null);

        $handler->__invoke(new ResumeApprovalCommand(42));
    }

    public function testNonChatApprovalsKeepTheLegacyOutcome(): void
    {
        [$handler, $mocks] = $this->handler();
        $approval = $this->approval('external:1', 'code_run');
        $mocks['approvals']->expects(self::any())->method('find')->with(42)->willReturn($approval);
        $mocks['registry']->expects(self::any())->method('get')->with(7, 'code_run')->willReturn($this->descriptor(ToolSource::Compute));

        $approval->expects(self::once())->method('markExecuted')->with('chat');
        $approval->expects(self::never())->method('markFailed');
        $mocks['continuation']->expects(self::never())->method('continueChat');

        $handler->__invoke(new ResumeApprovalCommand(42));
    }

    public function testSkipsApprovalsThatAreNoLongerApproved(): void
    {
        [$handler, $mocks] = $this->handler();
        $approval = $this->createMock(Approval::class);
        $approval->method('getStatus')->willReturn(Approval::STATUS_EXECUTED);
        $mocks['approvals']->expects(self::any())->method('find')->with(42)->willReturn($approval);
        $mocks['registry']->expects(self::never())->method('get');
        $mocks['continuation']->expects(self::never())->method('continueChat');

        $handler->__invoke(new ResumeApprovalCommand(42));
    }

    /**
     * @return Approval&MockObject
     */
    private function approval(string $requestedBy, string $tool = 'custom:acme'): Approval
    {
        $approval = $this->createMock(Approval::class);
        $approval->method('getId')->willReturn(42);
        $approval->method('getStatus')->willReturn(Approval::STATUS_APPROVED);
        $approval->method('getRequestedBy')->willReturn($requestedBy);
        $approval->method('getOwnerId')->willReturn(7);
        $approval->method('getTool')->willReturn($tool);
        $approval->method('getArgs')->willReturn(['order' => 1]);

        return $approval;
    }

    private function descriptor(ToolSource $source): ToolDescriptor
    {
        return new ToolDescriptor(
            name: ToolSource::Custom === $source ? 'custom:acme' : 'code_run',
            title: 'Test tool',
            description: 'Test tool',
            inputSchema: [],
            sideEffect: SideEffect::Write,
            source: $source,
            ownerId: 7,
            meta: ToolSource::Custom === $source ? ['toolId' => 9] : [],
        );
    }

    /**
     * @return array{0: ResumeApprovalCommandHandler, 1: array<string, MockObject>}
     */
    private function handler(): array
    {
        $approvals = $this->createMock(ApprovalRepository::class);
        $registry = $this->createMock(ToolRegistry::class);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($this->createStub(User::class));
        $customTools = $this->createMock(CustomToolRepository::class);
        $httpExecutor = $this->createMock(HttpToolExecutor::class);
        $mcpServers = $this->createMock(McpServerConfigRepository::class);
        $mcpClient = $this->createMock(McpClient::class);
        $notifier = $this->createMock(ApprovalRealtimeNotifier::class);
        $continuation = $this->createMock(ChatApprovalContinuationService::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new ResumeApprovalCommandHandler(
            $approvals,
            $registry,
            $users,
            $customTools,
            $httpExecutor,
            $mcpServers,
            $mcpClient,
            $notifier,
            $continuation,
            $bus,
            $logger,
        );

        return [$handler, [
            'approvals' => $approvals,
            'registry' => $registry,
            'customTools' => $customTools,
            'httpExecutor' => $httpExecutor,
            'notifier' => $notifier,
            'continuation' => $continuation,
        ]];
    }
}

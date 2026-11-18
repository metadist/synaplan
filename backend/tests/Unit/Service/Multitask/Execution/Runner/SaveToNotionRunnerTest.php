<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\McpServerConfig;
use App\Entity\Message;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\Notion\NotionFileUpload;
use App\Service\Mcp\Notion\NotionGateway;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\SaveToNotionRunner;
use App\Service\Multitask\Execution\Runner\UploadedNodeFiles;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Tool\Policy\PolicyOutcome;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class SaveToNotionRunnerTest extends TestCase
{
    private const PAGE = 'https://www.notion.so/Reports-39797ad60eb3806db59eef7653838664';

    public function testCreatesAChildPageWithTheEarlierStepsText(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Auto);
        $gateway->expects(self::once())->method('call')
            ->with($this->anything(), 'create-pages', [
                'parent' => ['page_id' => '39797ad6-0eb3-806d-b59e-ef7653838664'],
                'pages' => [['properties' => ['title' => 'Weekly report'], 'content' => 'All green.']],
            ])
            ->willReturn(['pages' => [['url' => 'https://www.notion.so/abc']]]);

        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('All green.'));

        $result = $this->runner($gateway)->run($this->node(['page' => self::PAGE, 'title' => 'Weekly report']), $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame('Saved the result to the new Notion page "Weekly report" (https://www.notion.so/abc).', $result->text);
        self::assertSame('https://www.notion.so/abc', $result->metadata['notion_url']);
    }

    public function testFailsHonestlyWithoutAPageLink(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->expects(self::never())->method('call');

        $result = $this->runner($gateway)->run($this->node(['page' => 'https://example.com']), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('nothing was saved to Notion', (string) $result->error);
    }

    public function testFailsWhenNotionIsNotConnected(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn(null);

        $result = $this->runner($gateway)->run($this->node(['page' => self::PAGE]), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Notion is not connected', (string) $result->error);
    }

    public function testRefusesBeforeUploadingWhenCreatingPagesIsBlocked(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Block);
        $gateway->expects(self::never())->method('call');
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('Body'));

        $result = $this->runner($gateway)->run($this->node(['page' => self::PAGE]), $ctx);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('blocked in your Notion permissions', (string) $result->error);
        self::assertStringContainsString('(/apps/notion?tab=permissions)', (string) $result->error);
    }

    public function testHandsTheAskToTheApprovalGateWhenCreatingPagesAsksFirst(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Approve);
        $gateway->expects(self::never())->method('call');
        $gate = $this->createMock(StepApprovalGate::class);
        $gate->expects(self::once())->method('consult')
            ->with($this->anything(), $this->anything(), 'skill:save_to_notion', $this->anything(), self::stringContains('set to ask first'))
            ->willReturn(NodeResult::failed('asks first'));
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('Body'));

        $result = $this->runner($gateway, $gate)->run($this->node(['page' => self::PAGE]), $ctx);

        self::assertFalse($result->isSuccessful());
        self::assertSame('asks first', $result->error);
    }

    public function testAsksFirstFailsClosedWithoutAnApprovalGate(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Approve);
        $gateway->expects(self::never())->method('call');
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('Body'));

        $result = $this->runner($gateway)->run($this->node(['page' => self::PAGE]), $ctx);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('set to ask first', (string) $result->error);
    }

    public function testThePlannerIsNotToldAboutNotionWhenCreatingPagesIsBlocked(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Block);

        $note = $this->runner($gateway)->describe()[0]->dynamicNote;

        self::assertNotNull($note);
        self::assertNull($note(1, []));
    }

    public function testReportsWhatNotionSaidWhenThePageIsNotCreated(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Auto);
        $gateway->method('call')->willThrowException(new McpClientException('Could not find page'));
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('Body'));

        $result = $this->runner($gateway)->run($this->node(['page' => self::PAGE]), $ctx);

        self::assertFalse($result->isSuccessful());
        self::assertSame('Notion did not create the page: Could not find page. Nothing was saved to Notion', $result->error);
    }

    public function testPausesForApprovalBeforeWriting(): void
    {
        $gateway = $this->createMock(NotionGateway::class);
        $gateway->method('writable')->willReturn($this->writableServer());
        $gateway->method('permission')->willReturn(PolicyOutcome::Auto);
        $gateway->expects(self::never())->method('call');
        $gate = $this->createMock(StepApprovalGate::class);
        $gate->method('consult')->willReturn(NodeResult::waitingApproval(5, []));
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('Body'));

        $result = $this->runner($gateway, $gate)->run($this->node(['page' => self::PAGE]), $ctx);

        self::assertTrue($result->isWaitingApproval());
    }

    public function testDescribesSaveToNotion(): void
    {
        $descriptors = $this->runner($this->createMock(NotionGateway::class))->describe();

        self::assertSame(Capability::SaveToNotion, $descriptors[0]->capability);
        self::assertTrue($descriptors[0]->requiresDynamicNote);
    }

    private function writableServer(): McpServerConfig
    {
        return (new McpServerConfig())->setUrl('https://mcp.notion.com/mcp');
    }

    private function runner(NotionGateway $gateway, ?StepApprovalGate $gate = null): SaveToNotionRunner
    {
        return new SaveToNotionRunner(
            $gateway,
            $this->createStub(NotionFileUpload::class),
            new UploadedNodeFiles(new NullLogger(), sys_get_temp_dir()),
            new NullLogger(),
            $gate,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function node(array $params): TaskNode
    {
        return new TaskNode('n2', Capability::SaveToNotion, ['n1'], [], $params);
    }

    private function context(): NodeContext
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn('save the weekly report to Notion');
        $m->method('getFileText')->willReturn('');
        $m->method('getLanguage')->willReturn('en');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());
        $m->method('getUserId')->willReturn(7);

        return new NodeContext($m, [], 7, ['language' => 'en']);
    }
}

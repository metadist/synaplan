<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\CustomTool;
use App\Repository\CustomToolRepository;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Execution\CustomHttpToolExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CustomHttpToolExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testTheOpenAiChannelNeverReachesThisExecutor(): void
    {
        $executor = new CustomHttpToolExecutor(new NullLogger());

        self::assertFalse($executor->supports($this->descriptor(ToolSource::Builtin, 'web_search')));
        self::assertTrue($executor->supports($this->descriptor(ToolSource::Custom, 'create_ticket')));
    }

    public function testGatewaySaysTheToolIsUnavailableWhenTheRowIsMissing(): void
    {
        $executor = new CustomHttpToolExecutor(
            new NullLogger(),
            $this->createStub(CustomToolRepository::class),
            $this->createStub(HttpToolExecutor::class),
        );
        $result = $executor->execute(
            $this->descriptor(ToolSource::Custom, 'create_ticket', ['toolId' => 9]),
            $this->invocation(ToolChannel::MESSAGES, 'create_ticket'),
        );

        self::assertSame('This custom tool is not available.', $result->text);
    }

    public function testResumeNamesTheMissingCustomTool(): void
    {
        $executor = new CustomHttpToolExecutor(new NullLogger(), $this->createStub(CustomToolRepository::class), $this->createStub(HttpToolExecutor::class));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Custom, 'create_ticket', ['toolId' => 9]),
            $this->invocation(ToolChannel::RESUME, 'create_ticket'),
        );

        self::assertSame('Custom tool create_ticket is no longer available', $result->text);
        self::assertTrue($result->details['missing']);
    }

    public function testASavedTaskKeepsTheRegistrySentence(): void
    {
        $executor = new CustomHttpToolExecutor(new NullLogger(), $this->createStub(CustomToolRepository::class), $this->createStub(HttpToolExecutor::class));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Custom, 'create_ticket', ['toolId' => 9]),
            $this->invocation(ToolChannel::SAVED_TASK, 'create_ticket'),
        );

        self::assertSame('Tool "create_ticket" is not registered', $result->text);
    }

    public function testSuccessCarriesTheCustomReference(): void
    {
        $row = $this->createStub(CustomTool::class);
        $row->method('getId')->willReturn(9);
        $row->method('isEnabled')->willReturn(true);
        $tools = $this->createStub(CustomToolRepository::class);
        $tools->method('find')->willReturn($row);
        $http = $this->createMock(HttpToolExecutor::class);
        $http->expects(self::once())->method('execute')->willReturn([
            'status' => 201,
            'summary' => 'Ticket SYN-1 created',
            'fields' => ['id' => 'SYN-1'],
            'truncated' => false,
            'body' => '',
        ]);
        $executor = new CustomHttpToolExecutor(new NullLogger(), $tools, $http);
        $result = $executor->execute(
            $this->descriptor(ToolSource::Custom, 'create_ticket', ['toolId' => 9]),
            $this->invocation(ToolChannel::SAVED_TASK, 'create_ticket'),
        );

        self::assertFalse($result->isError);
        self::assertSame('Ticket SYN-1 created', $result->text);
        self::assertSame('custom:9:201', $result->details['ref']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Tool\Execution\DocumentToolExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentToolExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testACallWithoutAnOpenDocumentFails(): void
    {
        $registry = $this->createMock(DocumentToolRegistry::class);
        $registry->expects(self::never())->method('get');
        $executor = new DocumentToolExecutor($registry, new NullLogger());
        $result = $executor->execute(
            $this->descriptor(ToolSource::Document, 'replace_text'),
            $this->invocation(ToolChannel::CHAT, 'replace_text'),
        );

        self::assertSame('There is no open document.', $result->text);
        self::assertTrue($result->isError);
    }

    public function testItOnlyClaimsDocumentTools(): void
    {
        $executor = new DocumentToolExecutor($this->createStub(DocumentToolRegistry::class), new NullLogger());

        self::assertTrue($executor->supports($this->descriptor(ToolSource::Document, 'replace_text')));
        self::assertFalse($executor->supports($this->descriptor(ToolSource::Mcp, 'search')));
    }
}

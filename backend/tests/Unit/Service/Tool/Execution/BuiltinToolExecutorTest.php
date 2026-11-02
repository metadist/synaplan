<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Service\Tool\Execution\BuiltinToolExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class BuiltinToolExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testTheOpenAiChannelRefusesEveryBuiltinExceptWebSearch(): void
    {
        $user = $this->createStub(User::class);
        $executor = new BuiltinToolExecutor();
        $result = $executor->execute(
            $this->descriptor(ToolSource::Builtin, 'analyze_image'),
            $this->invocation(ToolChannel::OPENAI, 'analyze_image', user: $user),
        );

        self::assertSame('Unknown Synaplan tool `analyze_image`.', $result->text);
        self::assertTrue($result->isError);
    }

    public function testOpenAiWebSearchUsesTheDirectToolWhenThereIsNoRegistry(): void
    {
        $search = $this->createMock(WebSearchTool::class);
        $search->expects(self::once())->method('execute')->willReturn([
            'text' => 'three hits',
            'isError' => false,
            'query' => 'synaplan',
        ]);
        $executor = new BuiltinToolExecutor(webSearch: $search);
        $result = $executor->execute(
            $this->descriptor(ToolSource::Builtin, WebSearchTool::NAME),
            $this->invocation(ToolChannel::OPENAI, WebSearchTool::NAME, user: $this->createStub(User::class)),
        );

        self::assertSame('three hits', $result->text);
        self::assertSame('WEB_SEARCH', $result->details['usageSource']);
        self::assertSame('synaplan', $result->details['usageInput']);
    }

    public function testAMissingUserIsAnUnknownTool(): void
    {
        $executor = new BuiltinToolExecutor();
        $result = $executor->execute(
            $this->descriptor(ToolSource::Builtin, WebSearchTool::NAME),
            $this->invocation(ToolChannel::MESSAGES, WebSearchTool::NAME),
        );

        self::assertSame('Unknown Synaplan tool `web_search`.', $result->text);
    }
}

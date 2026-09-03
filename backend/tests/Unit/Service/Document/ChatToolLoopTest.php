<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document;

use App\AI\Service\AiFacade;
use App\Service\Document\ChatToolLoop;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\SetCellsTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatToolLoopTest extends TestCase
{
    public function testExecutesToolThenStopsOnPlainReply(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('maxIterations')->willReturn(4);
        $config->method('maxOpsPerTurn')->willReturn(8);

        /** @var AiFacade&MockObject $facade */
        $facade = $this->createMock(AiFacade::class);
        $facade->expects(self::exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            [
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_1',
                    'function' => [
                        'name' => 'set_cells',
                        'arguments' => '{"sheet":"Sheet1","cells":[{"address":"A1","value":"Hi"}]}',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1],
            ],
            [
                'content' => 'Updated the sheet.',
                'tool_calls' => [],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            ],
        );

        $loop = new ChatToolLoop($facade, new DocumentToolRegistry([new SetCellsTool()]), $config, new NullLogger());
        $session = DocumentSession::empty('xlsx');
        $result = $loop->run([['role' => 'user', 'content' => 'Write Hi']], $session, ['provider' => 'test', 'model' => 'test'], 1);

        self::assertSame('Updated the sheet.', $result->content);
        self::assertTrue($session->hasMutations());
        self::assertSame('Hi', $session->spreadsheet()?->sheet('Sheet1')?->getCell('A1')?->value);
        self::assertSame(5, $result->usage['prompt_tokens']);
    }

    public function testUnknownToolIsReturnedAsErrorNotThrown(): void
    {
        $config = $this->createMock(DocumentToolsConfig::class);
        $config->method('maxIterations')->willReturn(2);
        $config->method('maxOpsPerTurn')->willReturn(4);

        $facade = $this->createMock(AiFacade::class);
        $facade->method('chat')->willReturnOnConsecutiveCalls(
            [
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_x',
                    'function' => ['name' => 'not_a_tool', 'arguments' => '{}'],
                ]],
            ],
            ['content' => 'Could not do that.', 'tool_calls' => []],
        );

        $loop = new ChatToolLoop($facade, new DocumentToolRegistry([]), $config, new NullLogger());
        $session = DocumentSession::empty('xlsx');
        $result = $loop->run([['role' => 'user', 'content' => 'x']], $session, [], 1);
        self::assertSame('Could not do that.', $result->content);
        self::assertFalse($session->hasMutations());
        self::assertFalse($session->operations[0]->ok);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\Processing\TurnWebSearch;
use App\Service\ModelConfigService;
use App\Service\RAG\ChatKnowledgeContextLoader;
use App\Service\Research\Eval\B0Adapter;
use App\Service\Research\Eval\CorpusFacts;
use App\Service\Research\Eval\CorpusItem;
use App\Service\Research\Eval\FrozenStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class B0AdapterTest extends TestCase
{
    public function testAWebItemForcesTheSearchVoteAndUsesThePagesTheTurnRead(): void
    {
        $web = $this->createMock(TurnWebSearch::class);
        $web->expects($this->once())->method('run')->willReturnCallback(
            function (Message $message, array $classification, mixed ...$rest): array {
                $this->assertNull($message->getId());
                $this->assertSame('What is 2024/1689?', $message->getText());
                $this->assertTrue($classification['web_search'] ?? false);
                $this->assertSame(3, $classification['read_pages'] ?? null);
                $this->assertNotEmpty($rest);

                return [
                    'classification' => [],
                    'searchResults' => [
                        'query' => 'act',
                        'results' => [['title' => 'Act', 'url' => 'https://example.com/act', 'page_content' => '2024/1689']],
                        'pages_read' => 1,
                    ],
                ];
            },
        );
        $knowledge = $this->createMock(ChatKnowledgeContextLoader::class);
        $knowledge->expects($this->never())->method('load');
        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->once())->method('chat')->with(
            $this->anything(),
            7,
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
        )->willReturn(['content' => 'Regulation 2024/1689.']);

        $text = $this->adapter($web, $knowledge, $ai)->answer($this->item(true, false), 7, null, null);

        $this->assertSame('Regulation 2024/1689.', $text);
    }

    public function testAFrozenItemDoesNotSearchAgain(): void
    {
        $dir = sys_get_temp_dir().'/research-frozen-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $store = new FrozenStore($dir);
        $store->put('fact-01', [
            'search' => ['query' => 'act', 'results' => [['title' => 'Act', 'url' => 'https://example.com']]],
            'knowledge' => '',
        ]);
        $web = $this->createMock(TurnWebSearch::class);
        $web->expects($this->never())->method('run');
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willReturn(['content' => 'From the frozen page.']);

        try {
            $text = $this->adapter($web, $this->createStub(ChatKnowledgeContextLoader::class), $ai)
                ->answer($this->item(true, false), 7, null, $store);
            $this->assertSame('From the frozen page.', $text);
        } finally {
            unlink($dir.'/fact-01.json');
            rmdir($dir);
        }
    }

    public function testAKnowledgeItemUsesTheChatLoaderAndDoesNotSearchTheWeb(): void
    {
        $web = $this->createMock(TurnWebSearch::class);
        $web->expects($this->never())->method('run');
        $knowledge = $this->createMock(ChatKnowledgeContextLoader::class);
        $knowledge->expects($this->once())->method('load')->with(
            $this->isInstanceOf(Message::class),
            'general',
            'contracts',
            5,
            0.3,
            null,
            true,
        )->willReturn(['context' => 'Kündigungsfrist: drei Monate', 'sources' => []]);
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willReturn(['content' => 'drei Monate']);

        $text = $this->adapter(
            $web,
            $knowledge,
            $ai,
        )->answer($this->item(false, true), 7, 'contracts', null);

        $this->assertSame('drei Monate', $text);
    }

    public function testAKnowledgeItemWithoutAFolderSendsNothing(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->never())->method('chat');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pass --folder. Nothing was sent.');

        $this->adapter($this->createStub(TurnWebSearch::class), $this->createStub(ChatKnowledgeContextLoader::class), $ai)
            ->answer($this->item(false, true), 7, null, null);
    }

    private function adapter(
        TurnWebSearch $web,
        ChatKnowledgeContextLoader $knowledge,
        AiFacade $ai,
    ): B0Adapter {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturnMap([['CHAT', 7, 379]]);
        $models->method('getProviderForModel')->willReturnMap([[379, 'anthropic']]);
        $models->method('getModelName')->willReturnMap([[379, 'claude-sonnet-5-5']]);

        return new B0Adapter($web, $knowledge, $ai, $models, new NullLogger());
    }

    private function item(bool $web, bool $knowledge): CorpusItem
    {
        return new CorpusItem(
            'fact-01',
            'current',
            'en',
            'What is 2024/1689?',
            'standard',
            $web,
            $knowledge,
            new CorpusFacts([], [], []),
            true,
            null,
        );
    }
}

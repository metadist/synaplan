<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Plug\WebSearch\WebSearchGateway;
use App\Service\Mcp\NotionLinkedPageRead;
use App\Service\Mcp\NotionLinkedPageReader;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\Processing\LinkedContentReader;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Processing\TurnWebSearch;
use App\Service\Message\SearchQueryGenerator;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TurnWebSearchWidgetTest extends TestCase
{
    public function testWidgetTurnDoesNotSearchEvenWhenTheMessageAsksTo(): void
    {
        $webSearch = $this->createMock(WebSearchGateway::class);
        $webSearch->method('isEnabled')->willReturn(true);
        $webSearch->expects($this->never())->method('search');

        $urls = $this->createMock(UrlContentService::class);
        $urls->expects($this->once())->method('extractUrls')->willReturn([]);

        $result = $this->turn($webSearch, null, $urls)->run(
            $this->message(),
            ['topic' => 'general', 'web_search' => true],
            ['tool_internet' => true],
            ['is_widget_mode' => true],
            [],
            new TurnStatus(null, new NullLogger()),
        );

        self::assertNull($result['searchResults']);
    }

    public function testAppChatStillSearchesWhenTheMessageAsksTo(): void
    {
        $webSearch = $this->createMock(WebSearchGateway::class);
        $webSearch->method('isEnabled')->willReturn(true);
        $webSearch->expects($this->once())->method('search')->willReturn([
            'results' => [
                ['title' => 'iPhone', 'url' => 'https://example.test/iphone', 'description' => 'A phone'],
            ],
        ]);

        $queries = $this->createStub(SearchQueryGenerator::class);
        $queries->method('generate')->willReturn('latest iPhone');

        $result = $this->turn($webSearch, $queries)->run(
            $this->message(),
            ['topic' => 'general'],
            [],
            [],
            [],
            new TurnStatus(null, new NullLogger()),
        );

        self::assertIsArray($result['searchResults']);
        self::assertNotEmpty($result['searchResults']['results']);
    }

    public function testANotionPageReadThroughTheConnectionSkipsWebSearch(): void
    {
        $url = 'https://www.notion.so/Synaplan-abc';
        $notion = $this->createStub(NotionLinkedPageReader::class);
        $notion->method('read')->willReturn(new NotionLinkedPageRead([
            ['url' => $url, 'success' => true, 'serverName' => 'Notion', 'text' => 'The Synaplan page.'],
        ]));

        $urls = $this->createStub(UrlContentService::class);
        $urls->method('extractUrls')->willReturn([$url]);

        $webSearch = $this->createMock(WebSearchGateway::class);
        $webSearch->method('isEnabled')->willReturn(true);
        $webSearch->expects(self::never())->method('search');

        $turn = new TurnWebSearch(
            $webSearch,
            $this->createStub(SearchQueryGenerator::class),
            $this->createStub(AttachmentSearchContextResolver::class),
            new LinkedContentReader($urls, new NullLogger(), null, null, null, $notion),
            new NullLogger(),
        );

        $result = $turn->run(
            (new Message())->setUserId(1)->setText('Was steht auf '.$url.' zu den Preisen?'),
            ['topic' => 'general', 'web_search' => true],
            ['tool_mcp' => true],
            [],
            [],
            new TurnStatus(null, new NullLogger()),
        );

        self::assertNull($result['searchResults']);
    }

    private function turn(
        WebSearchGateway $webSearch,
        ?SearchQueryGenerator $queries = null,
        ?UrlContentService $urls = null,
    ): TurnWebSearch {
        if (null === $urls) {
            $urls = $this->createStub(UrlContentService::class);
            $urls->method('extractUrls')->willReturn([]);
        }

        return new TurnWebSearch(
            $webSearch,
            $queries ?? $this->createStub(SearchQueryGenerator::class),
            $this->createStub(AttachmentSearchContextResolver::class),
            new LinkedContentReader($urls, new NullLogger()),
            new NullLogger(),
        );
    }

    private function message(): Message
    {
        return (new Message())->setUserId(4)->setText('Search the web for the latest iPhone');
    }
}

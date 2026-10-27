<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Mcp\NotionLinkedPageRead;
use App\Service\Mcp\NotionLinkedPageReader;
use App\Service\Message\Processing\LinkedContentReader;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LinkedContentReaderNotionTest extends TestCase
{
    public function testANotionLinkIsNotFetchedFromThePublicWeb(): void
    {
        $notionUrl = 'https://www.notion.so/Roadmap-abc123';
        $publicUrl = 'https://example.com/public';

        $urls = $this->createMock(UrlContentService::class);
        $urls->method('extractUrls')->willReturn([$notionUrl, $publicUrl]);
        $urls->expects($this->once())->method('fetchMultiple')->with([$publicUrl])->willReturn([]);

        $pages = $this->createMock(NotionLinkedPageReader::class);
        $pages->expects($this->once())->method('read')->willReturn(new NotionLinkedPageRead([[
            'url' => $notionUrl,
            'success' => false,
            'serverName' => 'Notion',
            'text' => 'Connect Notion under Apps to read this page. Nothing was read from the public web.',
        ]]));

        $reader = new LinkedContentReader($urls, new NullLogger(), null, null, null, $pages);
        $message = (new Message())->setUserId(4)->setText('see '.$notionUrl.' and '.$publicUrl);

        $classification = $reader->read(
            $message,
            ['tool_url_screenshot' => true, 'tool_mcp' => false],
            [],
            new TurnStatus(null, new NullLogger()),
        );

        self::assertSame([$notionUrl], $classification['notion_mcp_urls']);
        self::assertStringContainsString('Connect Notion under Apps', (string) $classification['url_content']);
    }
}

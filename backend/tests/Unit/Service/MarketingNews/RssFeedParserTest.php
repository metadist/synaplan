<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MarketingNews;

use App\Service\MarketingNews\RssFeedParser;
use PHPUnit\Framework\TestCase;

final class RssFeedParserTest extends TestCase
{
    private RssFeedParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RssFeedParser();
    }

    public function testParsesWordpressCompatibleFeed(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Synaplan Blog</title>
    <link>https://www.synaplan.com/news</link>
    <description>News</description>
    <item>
      <title>Synamail inside Outlook</title>
      <link>https://www.synaplan.com/blog/synamail</link>
      <guid isPermaLink="true">https://www.synaplan.com/blog/synamail</guid>
      <pubDate>Wed, 17 Jun 2026 00:00:00 +0000</pubDate>
      <dc:creator>Synaplan Team</dc:creator>
      <description>Summarise threads, translate, draft replies.</description>
      <content:encoded><![CDATA[<p>Full body here</p>]]></content:encoded>
      <enclosure url="https://www.synaplan.com/uploads/synamail.webp" type="image/webp" length="0" />
      <media:content url="https://www.synaplan.com/uploads/synamail.webp" medium="image" />
      <category>Outlook</category>
      <category>Open Source</category>
    </item>
  </channel>
</rss>
XML;

        $items = $this->parser->parse($xml);

        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame('Synamail inside Outlook', $item['title']);
        self::assertSame('https://www.synaplan.com/blog/synamail', $item['url']);
        self::assertSame('Summarise threads, translate, draft replies.', $item['excerpt']);
        self::assertSame('https://www.synaplan.com/uploads/synamail.webp', $item['imageUrl']);
        self::assertSame(['Outlook', 'Open Source'], $item['tags']);
        self::assertNotNull($item['publishedAt']);
        self::assertStringStartsWith('2026-06-17', $item['publishedAt']);
    }

    public function testFallsBackToContentEncodedWhenNoDescription(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Feed</title>
    <item>
      <title>No description post</title>
      <link>https://example.com/post</link>
      <content:encoded><![CDATA[<p>Body becomes excerpt</p>]]></content:encoded>
    </item>
  </channel>
</rss>
XML;

        $items = $this->parser->parse($xml);

        self::assertCount(1, $items);
        self::assertSame('Body becomes excerpt', $items[0]['excerpt']);
    }

    public function testSkipsItemsWithoutTitleOrValidUrl(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Feed</title>
    <item><link>https://example.com/no-title</link></item>
    <item><title>No link</title></item>
    <item><title>Bad url</title><link>not-a-url</link></item>
    <item><title>Good</title><link>https://example.com/good</link></item>
  </channel>
</rss>
XML;

        $items = $this->parser->parse($xml);

        self::assertCount(1, $items);
        self::assertSame('Good', $items[0]['title']);
    }

    public function testReturnsEmptyForEmptyOrInvalidXml(): void
    {
        self::assertSame([], $this->parser->parse(''));
        self::assertSame([], $this->parser->parse('not xml at all <<<'));
        self::assertSame([], $this->parser->parse('<html><body>nope</body></html>'));
    }

    public function testTruncatesLongExcerpt(): void
    {
        $longText = str_repeat('a', 400);
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Feed</title>
    <item>
      <title>Long</title>
      <link>https://example.com/long</link>
      <description>{$longText}</description>
    </item>
  </channel>
</rss>
XML;

        $items = $this->parser->parse($xml);

        self::assertCount(1, $items);
        self::assertLessThanOrEqual(280, mb_strlen($items[0]['excerpt']));
        self::assertStringEndsWith('…', $items[0]['excerpt']);
    }
}

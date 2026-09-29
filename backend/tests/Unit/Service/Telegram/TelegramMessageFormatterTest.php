<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramMessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramMessageFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'plain text is escaped' => ['a < b & c > d', 'a &lt; b &amp; c &gt; d'];
        yield 'bold' => ['**bold** and __also__', '<b>bold</b> and <b>also</b>'];
        yield 'italic' => ['an *italic* word', 'an <i>italic</i> word'];
        yield 'strikethrough' => ['~~gone~~', '<s>gone</s>'];
        yield 'heading' => ['## Title', '<b>Title</b>'];
        yield 'bullets' => ["- one\n* two", "• one\n• two"];
        yield 'inline code keeps markup literal' => ['use `**x** <y>`', 'use <code>**x** &lt;y&gt;</code>'];
        yield 'code block' => ["```php\necho '<b>';\n```", "<pre>echo '&lt;b&gt;';</pre>"];
        yield 'link' => ['[Docs](https://example.com/a?b=1&c="2")', '<a href="https://example.com/a?b=1&amp;c=&quot;2&quot;">Docs</a>'];
        yield 'non-http link stays text' => ['[x](javascript:alert(1))', '[x](javascript:alert(1))'];
        yield 'snake_case is untouched' => ['my_var_name', 'my_var_name'];
        yield 'multiplication stays' => ['2 * 3 * 4', '2 * 3 * 4'];
    }

    #[DataProvider('cases')]
    public function testMarkdownBecomesTelegramHtml(string $markdown, string $html): void
    {
        $this->assertSame($html, (new TelegramMessageFormatter())->toHtml($markdown));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Service\Email\MarkdownEmailFormatter;
use PHPUnit\Framework\TestCase;

final class MarkdownEmailFormatterTest extends TestCase
{
    public function testToHtmlConvertsMarkdownAndWrapsADocument(): void
    {
        $html = (new MarkdownEmailFormatter())->toHtml("# Title\n\nThis is **bold**.");

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringNotContainsString('**bold**', $html);
        self::assertStringNotContainsString('# Title', $html);
    }

    public function testToFragmentDoesNotWrapADocument(): void
    {
        $fragment = (new MarkdownEmailFormatter())->toFragment('# Title');

        self::assertStringContainsString('<h1>Title</h1>', $fragment);
        self::assertStringNotContainsString('<!DOCTYPE html>', $fragment);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Export;

use App\Service\Research\Export\ExportMarkdown;
use PHPUnit\Framework\TestCase;

final class ExportMarkdownTest extends TestCase
{
    public function testAnAutolinkStaysALinkInTheWordDocument(): void
    {
        self::assertSame(
            'See <https://example.com/a?b=1> and &lt;b>bold&lt;/b>',
            ExportMarkdown::forDocument('See <https://example.com/a?b=1> and <b>bold</b>'),
        );
    }

    public function testAnHtmlTagThatLooksLikeAnAutolinkIsStillEscaped(): void
    {
        self::assertSame(
            '&lt;img src="http://x/1"> &lt;http://x/2 onerror=y>',
            ExportMarkdown::forDocument('<img src="http://x/1"> <http://x/2 onerror=y>'),
        );
    }

    public function testImagesBecomeLinksOutsideCodeOnly(): void
    {
        self::assertSame(
            '[chart](http://x/1.png) \\![ref][r] `![kept](http://x/2)`',
            ExportMarkdown::forPage('![chart](http://x/1.png) ![ref][r] `![kept](http://x/2)`'),
        );
    }
}

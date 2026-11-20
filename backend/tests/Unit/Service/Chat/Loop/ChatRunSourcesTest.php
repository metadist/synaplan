<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunSources;
use PHPUnit\Framework\TestCase;

final class ChatRunSourcesTest extends TestCase
{
    public function testSearchResultsAndReadPagesAreSourcesWithTheTextTheModelSaw(): void
    {
        $sources = ChatRunSources::read([
            ['role' => 'user', 'content' => "URL: https://user.example.com/\n"],
            ['role' => 'tool', 'name' => 'web_search', 'content' => "Web Search Results for: \"x\"\n\nFound 2 results:\n\n[1] First\nURL: https://Example.com/a/\nDescription: One fact.\n\n[2] Second\nURL: https://example.org/b\nSnippets:\n  - Two facts.\n"],
            ['role' => 'tool', 'name' => 'web_fetch', 'content' => "Title: First page\nURL: https://example.com/a\n\nThe whole page.\n[1] A footnote."],
            ['role' => 'tool', 'name' => 'web_fetch', 'content' => 'The page could not be read.'],
            ['role' => 'tool', 'name' => 'custom_lookup', 'content' => "[1] Other\nURL: https://tool.example.net/\n"],
        ]);

        self::assertSame(['https://example.com/a', 'https://example.org/b'], array_keys($sources));
        self::assertSame("First\nDescription: One fact.\nFirst page\n\nThe whole page.\n[1] A footnote.", $sources['https://example.com/a']);
        self::assertSame("Second\nSnippets:\n  - Two facts.", $sources['https://example.org/b']);
    }

    public function testLinksToReadPagesBecomeMarkersAndOthersStay(): void
    {
        $sources = ['https://example.com/a' => 'page a', 'https://example.org/b' => 'page b'];
        $cited = ChatRunSources::cite(
            'One [First](https://example.com/a/ "t"). Two https://example.org/b. Three <https://example.com/a>. Four [Other](https://other.example.net/).',
            $sources,
        );

        self::assertSame('One First [e1]. Two [e2]. Three [e1]. Four [Other](https://other.example.net/).', ChatRunSources::shown($cited['report']));
        self::assertSame('One [e1]. Two [e2]. Three [e1]. Four [Other](https://other.example.net/).', ChatRunSources::withoutLabels($cited['report']));
        self::assertSame(['e1' => 'page a', 'e2' => 'page b'], $cited['passages']);
    }

    public function testAUrlWithBracketsIsOneLink(): void
    {
        $sources = ['https://de.wikipedia.org/wiki/Langenfeld_(Rheinland)' => 'page'];
        $cited = ChatRunSources::cite(
            'It has 59,821 people ([Wikipedia](https://de.wikipedia.org/wiki/Langenfeld_(Rheinland))). Also https://de.wikipedia.org/wiki/Langenfeld_(Rheinland).',
            $sources,
        );

        self::assertSame('It has 59,821 people (Wikipedia [e1]). Also [e1].', ChatRunSources::shown($cited['report']));
    }

    public function testAnAnswerWithoutLinksCitesNothing(): void
    {
        $cited = ChatRunSources::cite('Nothing linked here.', ['https://example.com/a' => 'page a']);

        self::assertSame('Nothing linked here.', $cited['report']);
        self::assertSame([], $cited['passages']);
    }
}

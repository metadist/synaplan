<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\WebSearch\SearchResultSet;
use PHPUnit\Framework\TestCase;

final class SearchResultSetTest extends TestCase
{
    public function testFormatForAiCountsResultsWhenMetadataIsMissing(): void
    {
        $set = SearchResultSet::fromLegacyArray([
            'query' => 'cats',
            'results' => [
                ['title' => 'Cat', 'url' => 'https://example.test/cat'],
                ['title' => 'Kitten', 'url' => 'https://example.test/kitten'],
            ],
        ]);

        $text = $set->formatForAi();

        $this->assertStringContainsString('Web Search Results for: "cats"', $text);
        $this->assertStringContainsString('Found 2 results:', $text);
        $this->assertStringContainsString('[1] Cat', $text);
        $this->assertStringContainsString('URL: https://example.test/cat', $text);
        $this->assertStringContainsString('[2] Kitten', $text);
    }

    public function testFormatForAiToleratesMissingTitleAndUrl(): void
    {
        $set = SearchResultSet::fromLegacyArray([
            'query' => 'partial',
            'results' => [
                ['description' => 'No title or url on this hit'],
            ],
        ]);

        $text = $set->formatForAi();

        $this->assertStringContainsString('[1] ', $text);
        $this->assertStringContainsString("URL: \n", $text);
        $this->assertStringContainsString('Description: No title or url on this hit', $text);
    }

    public function testFormatForAiOmitsAnswerWhenWantAnswerIsFalse(): void
    {
        $set = SearchResultSet::fromResults(
            'cats',
            [],
            ['total' => 0],
            new \App\Plug\WebSearch\ProviderAnswer('Cats are mammals.'),
        );

        $without = $set->formatForAi(false);
        $with = $set->formatForAi(true);

        $this->assertStringNotContainsString('Answer:', $without);
        $this->assertStringContainsString('Answer:', $with);
        $this->assertStringContainsString('Cats are mammals.', $with);
    }
}

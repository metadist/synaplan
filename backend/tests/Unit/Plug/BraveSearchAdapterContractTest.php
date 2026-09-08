<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\WebSearch\Adapter\BraveSearchAdapter;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchGateway;
use App\Plug\WebSearch\WebSearchQuery;
use App\Service\Search\BraveSearchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * C1: adapter + gateway stay byte-identical with BraveSearchService output.
 */
final class BraveSearchAdapterContractTest extends TestCase
{
    public function testLegacyArrayAndAiTextMatchRecordedBraveFixture(): void
    {
        $legacy = $this->loadLegacyFixture();
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('search')->willReturn($legacy);
        $brave->method('isEnabled')->willReturn(true);

        $set = (new BraveSearchAdapter($brave))->search(WebSearchQuery::fromLegacy('synaplan open source'));

        $this->assertSame($legacy, $set->toLegacyArray());
        $this->assertSame(
            $this->realBraveFormatter()->formatResultsForAI($legacy),
            $set->formatForAi(),
        );
        $this->assertSame(
            $this->expectedAiText(),
            $set->formatForAi(),
        );
    }

    public function testGatewaySearchAndFormatStayByteIdentical(): void
    {
        $legacy = $this->loadLegacyFixture();
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('search')->willReturn($legacy);
        $brave->method('isEnabled')->willReturn(true);

        $gateway = WebSearchGateway::forProvider(new BraveSearchAdapter($brave));
        $returned = $gateway->search('synaplan open source');

        $this->assertSame($legacy, $returned);
        $this->assertSame(
            $this->realBraveFormatter()->formatResultsForAI($legacy),
            $gateway->formatResultsForAI($returned),
        );
        $this->assertTrue($gateway->isEnabled());
    }

    public function testSearchResultSetRoundTripPreservesLegacyKeys(): void
    {
        $legacy = $this->loadLegacyFixture();

        $this->assertSame($legacy, SearchResultSet::fromLegacyArray($legacy)->toLegacyArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLegacyFixture(): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/web_search/brave/legacy_search.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function expectedAiText(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/web_search/brave/expected-ai.txt');
    }

    private function realBraveFormatter(): BraveSearchService
    {
        return new BraveSearchService(
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            'test-key',
            'https://api.search.brave.com/res/v1/web/search',
            true,
            5,
            'us',
            'en',
        );
    }
}

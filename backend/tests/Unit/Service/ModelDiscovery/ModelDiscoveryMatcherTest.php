<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Model\ModelDiscoveryIgnoreList;
use App\Service\ModelDiscovery\ModelDiscoveryMatcher;
use App\Service\ModelDiscovery\OpenRouterModelSource;
use App\Service\ModelDiscovery\UpstreamModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ModelDiscoveryMatcherTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/ModelDiscovery/openrouter-models-2026-09-24.json';

    private const NOW = '2026-09-24T12:00:00Z';

    /**
     * Catalog as on main before PR #2157 — missing Opus 5.5 / GPT-6 Sol / Luna.
     *
     * @return list<array{service: string, providerId: string}>
     */
    private function pre2157Catalog(): array
    {
        return [
            ['service' => 'Anthropic', 'providerId' => 'claude-opus-5'],
            ['service' => 'Anthropic', 'providerId' => 'claude-fable-5-1'],
            ['service' => 'Anthropic', 'providerId' => 'claude-haiku-4-5-20251001'],
            ['service' => 'OpenAI', 'providerId' => 'gpt-6-astra'],
            ['service' => 'OpenAI', 'providerId' => 'gpt-5.6-sol'],
            ['service' => 'xAI', 'providerId' => 'grok-4.7'],
            ['service' => 'Google', 'providerId' => 'gemini-3.1-pro-preview'],
            ['service' => 'Google', 'providerId' => 'gemini-3.8-flash'],
            ['service' => 'Google', 'providerId' => 'gemini-3.7-flash'],
            // Retired row must still count as known.
            ['service' => 'OpenAI', 'providerId' => 'gpt-5.3'],
        ];
    }

    public function testWithRealIgnoreListFindsExactlyTheThreeNewModels(): void
    {
        $result = $this->match($this->fixtureUpstream(), ModelDiscoveryIgnoreList::ENTRIES);

        $this->assertSame(
            [
                'anthropic/claude-opus-5.5',
                'openai/gpt-6-luna',
                'openai/gpt-6-sol',
            ],
            $this->sortedIds($result->new),
        );
        $this->assertSame(
            [
                'openai/gpt-6-astra-pro',
                'openai/gpt-6-luna-pro',
                'openai/gpt-6-sol-pro',
            ],
            $this->sortedIds(array_map(static fn (array $e): UpstreamModel => $e['model'], $result->ignored)),
        );
    }

    public function testEmptyIgnoreListAlsoReportsProVariantsAsNew(): void
    {
        $result = $this->match($this->fixtureUpstream(), []);

        $this->assertSame(
            [
                'anthropic/claude-opus-5.5',
                'openai/gpt-6-astra-pro',
                'openai/gpt-6-luna',
                'openai/gpt-6-luna-pro',
                'openai/gpt-6-sol',
                'openai/gpt-6-sol-pro',
            ],
            $this->sortedIds($result->new),
        );
        $this->assertSame([], $result->ignored);
    }

    public function testSkipsBatchAliasesUnmappedVendorsAndOutOfWindow(): void
    {
        $result = $this->match($this->fixtureUpstream(), ModelDiscoveryIgnoreList::ENTRIES);
        $allReported = array_merge(
            $this->sortedIds($result->new),
            $this->sortedIds(array_map(static fn (array $e): UpstreamModel => $e['model'], $result->ignored)),
        );

        foreach ($allReported as $id) {
            $this->assertStringNotContainsString(':', $id);
            $this->assertStringStartsNotWith('~', $id);
            $this->assertStringNotContainsString('deepseek/', $id);
            $this->assertStringNotContainsString('qwen/', $id);
        }

        // Older than the 30-day window (created 2026-08-12) must not appear.
        $this->assertNotContains('x-ai/grok-4.6', $allReported);
        $this->assertNotContains('google/gemini-3.7-flash', $allReported);
    }

    public function testDatedCatalogIdMatchesUndatedUpstreamId(): void
    {
        $upstream = [
            new UpstreamModel(
                openRouterId: 'anthropic/claude-haiku-4.5',
                vendor: 'anthropic',
                created: new \DateTimeImmutable('2026-09-20T00:00:00Z'),
                priceInPer1M: 1.0,
                priceOutPer1M: 5.0,
                cacheReadPer1M: 0.1,
            ),
        ];

        $result = $this->match($upstream, []);

        $this->assertSame([], $result->new, 'claude-haiku-4-5-20251001 must cover anthropic/claude-haiku-4.5');
    }

    public function testRetiredCatalogRowCountsAsKnown(): void
    {
        $upstream = [
            new UpstreamModel(
                openRouterId: 'openai/gpt-5.3',
                vendor: 'openai',
                created: new \DateTimeImmutable('2026-09-20T00:00:00Z'),
                priceInPer1M: 1.0,
                priceOutPer1M: 2.0,
                cacheReadPer1M: null,
            ),
        ];

        $result = $this->match($upstream, []);

        $this->assertSame([], $result->new);
    }

    public function testObsoleteIgnoreWhenIdGoneUpstream(): void
    {
        $ignore = [
            'openai/vanished-pro' => [
                'reason' => 'test',
                'decidedOn' => '2026-09-01',
            ],
        ];

        $result = $this->match($this->fixtureUpstream(), $ignore);

        $this->assertCount(1, $result->obsoleteIgnores);
        $this->assertSame('openai/vanished-pro', $result->obsoleteIgnores[0]['openRouterId']);
        $this->assertSame('gone_upstream', $result->obsoleteIgnores[0]['why']);
    }

    public function testObsoleteIgnoreWhenKeyNowInCatalog(): void
    {
        $ignore = [
            'openai/gpt-6-astra-pro' => [
                'reason' => 'was OpenRouter-only',
                'decidedOn' => '2026-09-01',
            ],
        ];
        $catalog = $this->pre2157Catalog();
        $catalog[] = ['service' => 'OpenAI', 'providerId' => 'gpt-6-astra-pro'];

        $result = (new ModelDiscoveryMatcher())->match(
            $this->fixtureUpstream(),
            $catalog,
            $ignore,
            new \DateTimeImmutable(self::NOW),
            30,
        );

        $this->assertNotEmpty($result->obsoleteIgnores);
        $ids = array_column($result->obsoleteIgnores, 'openRouterId');
        $this->assertContains('openai/gpt-6-astra-pro', $ids);
        $match = array_values(array_filter(
            $result->obsoleteIgnores,
            static fn (array $e): bool => 'openai/gpt-6-astra-pro' === $e['openRouterId'],
        ))[0];
        $this->assertSame('now_in_catalog', $match['why']);
    }

    /**
     * @param list<UpstreamModel>                                     $upstream
     * @param array<string, array{reason: string, decidedOn: string}> $ignore
     */
    private function match(array $upstream, array $ignore): \App\Service\ModelDiscovery\DiscoveryMatchResult
    {
        return (new ModelDiscoveryMatcher())->match(
            $upstream,
            $this->pre2157Catalog(),
            $ignore,
            new \DateTimeImmutable(self::NOW),
            30,
        );
    }

    /**
     * @return list<UpstreamModel>
     */
    private function fixtureUpstream(): array
    {
        $client = new MockHttpClient([
            new MockResponse((string) file_get_contents(self::FIXTURE)),
        ]);

        return (new OpenRouterModelSource($client))->fetch();
    }

    /**
     * @param list<UpstreamModel> $models
     *
     * @return list<string>
     */
    private function sortedIds(array $models): array
    {
        $ids = array_map(static fn (UpstreamModel $m): string => $m->openRouterId, $models);
        sort($ids);

        return $ids;
    }
}

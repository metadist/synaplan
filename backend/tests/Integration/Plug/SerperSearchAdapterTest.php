<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plug;

use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\WebSearchQuery;
use PHPUnit\Framework\TestCase;
use Plugin\SerperSearch\Plug\SerperSearchAdapter;
use Plugin\SerperSearch\Plug\SerperSearchResultMapper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Contract coverage for the serper_search reference plugin. Lives in the core
 * suite (not the plugin dir) so it runs identically in the dev container and in
 * CI; the plugin classes autoload via the PSR-4 registration in tests/bootstrap.
 */
final class SerperSearchAdapterTest extends TestCase
{
    public function testMapsFixtureOrganicResultsAndSkipsRowsWithoutLink(): void
    {
        $set = SerperSearchResultMapper::map('synaplan ai platform', $this->fixture(), SerperSearchAdapter::KEY);

        // SearchResultSet stores legacy rows (the Brave-shaped array) in ->results.
        self::assertCount(2, $set->results, 'the organic row without a link is dropped');
        self::assertSame('Synaplan — AI knowledge platform', $set->results[0]['title']);
        self::assertSame('https://synaplan.com/', $set->results[0]['url']);
        self::assertSame('2026-08-01', $set->results[0]['age']);
        self::assertSame('', $set->results[1]['age'], 'a row without a date has an empty age');
    }

    public function testFreshnessCountryLanguageBecomeSerperQueryFields(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse(json_encode($this->fixture(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $adapter = new SerperSearchAdapter($client, $this->keyStore('serper-test-key'));
        $adapter->search(new WebSearchQuery('synaplan', ['freshness' => 'pw', 'country' => 'DE', 'search_lang' => 'de']));

        self::assertIsArray($captured);
        self::assertSame('qdr:w', $captured['tbs'] ?? null);
        self::assertSame('de', $captured['gl'] ?? null);
        self::assertSame('de', $captured['hl'] ?? null);
    }

    public function testMissingKeyIsUnavailableAndSearchReturnsEmptySet(): void
    {
        $adapter = new SerperSearchAdapter(new MockHttpClient(), $this->keyStore(null));

        self::assertFalse($adapter->health()->available);
        self::assertSame([], $adapter->search(new WebSearchQuery('synaplan'))->results);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = $this->pluginDir().'/backend/tests/fixtures/serper-search.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function pluginDir(): string
    {
        foreach (array_filter([getenv('PLUGINS_DIR') ?: null, '/plugins', \dirname(__DIR__, 3).'/plugins']) as $dir) {
            if (is_dir($dir.'/serper_search')) {
                return $dir.'/serper_search';
            }
        }

        self::fail('serper_search plugin directory not found');
    }

    private function keyStore(?string $key): PlugKeyStore
    {
        // BypassFinals (phpunit.xml.dist) makes the final PlugKeyStore mockable.
        return new class($key) extends PlugKeyStore {
            public function __construct(private readonly ?string $key)
            {
            }

            public function getKey(string $provider): ?string
            {
                return $this->key;
            }
        };
    }
}

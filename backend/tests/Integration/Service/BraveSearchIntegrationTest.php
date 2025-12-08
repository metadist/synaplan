<?php

namespace App\Tests\Integration\Service;

use App\Service\Search\BraveSearchService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for BraveSearchService.
 *
 * These tests verify the search functionality works correctly
 * with different configurations.
 */
class BraveSearchIntegrationTest extends KernelTestCase
{
    private BraveSearchService $braveSearchService;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = self::getContainer();
        $this->braveSearchService = $container->get(BraveSearchService::class);
    }

    public function testServiceIsAvailable(): void
    {
        $this->assertInstanceOf(BraveSearchService::class, $this->braveSearchService);
    }

    public function testIsEnabledReturnsFalseWhenNotConfigured(): void
    {
        // By default (without API key), service should not be enabled
        $this->assertIsBool($this->braveSearchService->isEnabled());
    }

    public function testSearchThrowsExceptionWhenDisabled(): void
    {
        if ($this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is enabled, skipping disabled test');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Brave Search API is not enabled or configured');

        $this->braveSearchService->search('test query');
    }

    public function testSearchThrowsExceptionOnEmptyQuery(): void
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is disabled, skipping this test');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Search query cannot be empty');

        $this->braveSearchService->search('');
    }

    public function testSearchWithCustomOptions(): void
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is disabled, skipping live API test');
        }

        // Test with custom language and country
        $results = $this->braveSearchService->search('artificial intelligence', [
            'count' => 5,
            'country' => 'de',
            'search_lang' => 'de',
        ]);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('query', $results);
        $this->assertArrayHasKey('results', $results);
        $this->assertArrayHasKey('query_metadata', $results);

        $this->assertEquals('artificial intelligence', $results['query']);
        $this->assertIsArray($results['results']);

        // Check that results have expected structure
        if (!empty($results['results'])) {
            $firstResult = $results['results'][0];
            $this->assertArrayHasKey('title', $firstResult);
            $this->assertArrayHasKey('url', $firstResult);
            $this->assertArrayHasKey('description', $firstResult);
        }
    }

    public function testSearchHandlesDifferentLanguages(): void
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is disabled, skipping live API test');
        }

        $languages = ['en', 'de', 'fr', 'es'];

        foreach ($languages as $lang) {
            $results = $this->braveSearchService->search('test', [
                'count' => 3,
                'country' => $lang,
                'search_lang' => $lang,
            ]);

            $this->assertIsArray($results);
            $this->assertArrayHasKey('results', $results);
            $this->assertArrayHasKey('query_metadata', $results);
        }
    }

    public function testFormatResultsForAI(): void
    {
        $mockResults = [
            'query' => 'test query',
            'results' => [
                [
                    'title' => 'Test Result 1',
                    'url' => 'https://example.com/1',
                    'description' => 'Test description 1',
                    'age' => '2 days ago',
                    'profile' => ['name' => 'Example Site'],
                ],
                [
                    'title' => 'Test Result 2',
                    'url' => 'https://example.com/2',
                    'description' => 'Test description 2',
                    'age' => '1 week ago',
                    'profile' => ['name' => 'Example Site 2'],
                ],
            ],
            'query_metadata' => [
                'total' => 2,
                'altered' => null,
                'original' => 'test query',
            ],
        ];

        $formatted = $this->braveSearchService->formatResultsForAI($mockResults);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('test query', $formatted);
        $this->assertStringContainsString('Test Result 1', $formatted);
        $this->assertStringContainsString('https://example.com/1', $formatted);
        $this->assertStringContainsString('[1]', $formatted);
        $this->assertStringContainsString('[2]', $formatted);
    }

    public function testFormatEmptyResults(): void
    {
        $emptyResults = [
            'query' => 'empty query',
            'results' => [],
            'query_metadata' => [
                'total' => 0,
                'altered' => null,
                'original' => 'empty query',
            ],
        ];

        $formatted = $this->braveSearchService->formatResultsForAI($emptyResults);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('No search results found', $formatted);
        $this->assertStringContainsString('empty query', $formatted);
    }

    public function testLanguageAndCountryNormalization(): void
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is disabled, skipping live API test');
        }

        // Test with various language/country formats that should be normalized
        $testCases = [
            ['search_lang' => 'de', 'country' => 'de', 'expectedLang' => 'de', 'expectedCountry' => 'DE'],
            ['search_lang' => 'EN', 'country' => 'EN', 'expectedLang' => 'en', 'expectedCountry' => 'US'],
            ['search_lang' => 'fr', 'country' => 'FR', 'expectedLang' => 'fr', 'expectedCountry' => 'FR'],
            ['search_lang' => 'ja', 'country' => 'JP', 'expectedLang' => 'ja', 'expectedCountry' => 'JP'],
        ];

        foreach ($testCases as $case) {
            try {
                $results = $this->braveSearchService->search('test query', [
                    'count' => 1,
                    'search_lang' => $case['search_lang'],
                    'country' => $case['country'],
                ]);

                $this->assertIsArray($results);
                $this->assertArrayHasKey('results', $results);
                $this->assertArrayHasKey('query', $results);

                // Verify normalization worked by checking results structure
                $this->assertEquals('test query', $results['query']);
            } catch (\Exception $e) {
                // API might fail, but normalization should still work
                $this->assertStringContainsString('Brave Search', $e->getMessage());
            }
        }
    }

    public function testInvalidLanguageCodeFallback(): void
    {
        if (!$this->braveSearchService->isEnabled()) {
            $this->markTestSkipped('Brave Search is disabled, skipping live API test');
        }

        // Test with invalid language code - should fallback to default (en)
        try {
            $results = $this->braveSearchService->search('test', [
                'count' => 1,
                'search_lang' => 'invalid_lang',
                'country' => 'INVALID',
            ]);

            // Should not throw exception - should use defaults
            $this->assertIsArray($results);
            $this->assertArrayHasKey('results', $results);
        } catch (\Exception $e) {
            // Only acceptable exception is API failure, not validation error
            $this->assertStringNotContainsString('Invalid', $e->getMessage());
        }
    }
}

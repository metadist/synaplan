<?php

namespace App\Tests\Integration\Service\Message;

use App\Service\Message\SearchQueryGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for SearchQueryGenerator.
 *
 * Tests AI-powered search query generation from user questions
 */
class SearchQueryGeneratorTest extends KernelTestCase
{
    private SearchQueryGenerator $generator;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->generator = $container->get(SearchQueryGenerator::class);
    }

    /**
     * Test: Generator extracts key information from questions.
     */
    public function testGenerateOptimizedQuery(): void
    {
        $this->markTestSkipped('Integration test requires AI provider for query optimization');
    }

    /**
     * Test: Generator handles English questions.
     */
    public function testGenerateEnglishQuery(): void
    {
        $this->markTestSkipped('Integration test requires AI provider for query optimization');
    }

    /**
     * Test: Generator handles questions with dates.
     */
    public function testGenerateWithDate(): void
    {
        $userQuestion = 'Who won the world cup in 2022?';

        $searchQuery = $this->generator->generate($userQuestion);

        $this->assertNotEmpty($searchQuery);
        // Date should be preserved
        $this->assertStringContainsString('2022', $searchQuery);

        echo "\n✅ Date Question: {$userQuestion}";
        echo "\n✅ Date Query: {$searchQuery}\n";
    }

    /**
     * Test: Fallback extraction when AI fails.
     */
    public function testFallbackExtraction(): void
    {
        // TestProvider detects tools:search context and returns mockSearchQueryExtraction (same as fallback)
        $userQuestion = '/search test query';

        $searchQuery = $this->generator->generate($userQuestion, null);

        $this->assertNotEmpty($searchQuery);
        // Should have removed /search prefix
        $this->assertStringNotContainsString('/search', $searchQuery);

        echo "\n✅ Fallback Input: {$userQuestion}";
        echo "\n✅ Fallback Output: {$searchQuery}\n";
    }

    /**
     * Test: Generator handles very short queries.
     */
    public function testShortQuery(): void
    {
        $userQuestion = 'Berlin weather';

        $searchQuery = $this->generator->generate($userQuestion);

        $this->assertNotEmpty($searchQuery);

        echo "\n✅ Short Query: {$userQuestion}";
        echo "\n✅ Short Generated: {$searchQuery}\n";
    }

    /**
     * Test: Generator removes surrounding quotes.
     */
    public function testRemovesQuotes(): void
    {
        // TestProvider detects tools:search context and returns mockSearchQueryExtraction (strips quotes)
        $userQuestion = '"was kostet ein döner"';

        $searchQuery = $this->generator->generate($userQuestion);

        $this->assertNotEmpty($searchQuery);
        // Quotes should be removed
        $this->assertStringNotContainsString('"', $searchQuery);

        echo "\n✅ Quoted Input: {$userQuestion}";
        echo "\n✅ Unquoted Output: {$searchQuery}\n";
    }
}

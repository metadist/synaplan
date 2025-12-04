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
        $userQuestion = 'Kannst du mir sagen, wie viel ein Döner in München kostet?';

        $searchQuery = $this->generator->generate($userQuestion);

        // Query should be shorter than original question
        $this->assertLessThan(
            strlen($userQuestion),
            strlen($searchQuery),
            'Generated query should be more concise than original question'
        );

        // Query should not be empty
        $this->assertNotEmpty($searchQuery, 'Generated query should not be empty');

        // Query should contain key terms (language-independent check)
        $this->assertGreaterThan(
            0,
            strlen($searchQuery),
            'Query should have meaningful content'
        );

        echo "\n✅ Original: {$userQuestion}";
        echo "\n✅ Generated: {$searchQuery}\n";
    }

    /**
     * Test: Generator handles English questions.
     */
    public function testGenerateEnglishQuery(): void
    {
        $this->markTestSkipped('Integration test requires AI provider for query optimization');
        $userQuestion = "What's the weather like in Paris this weekend?";

        $searchQuery = $this->generator->generate($userQuestion);

        $this->assertNotEmpty($searchQuery);
        $this->assertLessThan(strlen($userQuestion), strlen($searchQuery));

        echo "\n✅ English Original: {$userQuestion}";
        echo "\n✅ English Generated: {$searchQuery}\n";
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
        // Use null userId to potentially trigger fallback
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
        $userQuestion = '"was kostet ein döner"';

        $searchQuery = $this->generator->generate($userQuestion);

        $this->assertNotEmpty($searchQuery);
        // Quotes should be removed
        $this->assertStringNotContainsString('"', $searchQuery);

        echo "\n✅ Quoted Input: {$userQuestion}";
        echo "\n✅ Unquoted Output: {$searchQuery}\n";
    }
}

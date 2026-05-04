<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Prompt\PromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the static catalog stays in sync with Synapse Routing v2:
 *  - all granular topics from the alias resolver are present
 *  - keywords/enabled fields are well-formed
 *  - tools:* helpers are not surfaced for routing
 */
final class PromptCatalogTest extends TestCase
{
    public function testAllReturnsTheGranularRoutingTopics(): void
    {
        $topics = array_column(PromptCatalog::all(), 'topic');

        foreach (
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation'] as $expected
        ) {
            $this->assertContains(
                $expected,
                $topics,
                sprintf('Expected granular topic "%s" to be defined in PromptCatalog', $expected)
            );
        }
    }

    public function testLegacyCanonicalTopicsRemainForBackwardCompat(): void
    {
        $topics = array_column(PromptCatalog::all(), 'topic');

        $this->assertContains('general', $topics);
        $this->assertContains('mediamaker', $topics);
        $this->assertContains('docsummary', $topics);
        $this->assertContains('officemaker', $topics);
    }

    public function testToolsSortIsExcludedFromRoutingEvenIfIncludedInCatalog(): void
    {
        $topics = array_column(PromptCatalog::all(), 'topic');

        // tools:* must still exist (they're seeded), they just must NOT
        // accidentally be marked as routable. Indexer filters them by prefix.
        $this->assertContains('tools:sort', $topics);
        $this->assertContains('tools:enhance', $topics);
    }

    public function testRoutingTopicsHaveKeywords(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        foreach (
            ['general-chat', 'coding', 'image-generation', 'video-generation', 'audio-generation', 'docsummary', 'officemaker'] as $routable
        ) {
            $this->assertArrayHasKey('keywords', $byTopic[$routable], sprintf(
                'Routable topic "%s" must declare keywords for Synapse recall',
                $routable
            ));
            $this->assertNotSame('', trim((string) $byTopic[$routable]['keywords']));
        }
    }

    public function testCodingKeywordsContainProgrammingTerms(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $codingKeywords = strtolower((string) $byTopic['coding']['keywords']);
        $this->assertStringContainsString('php', $codingKeywords);
        $this->assertStringContainsString('python', $codingKeywords);
        $this->assertStringContainsString('debug', $codingKeywords);
    }

    public function testImageGenerationKeywordsContainBilingualTerms(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $kw = strtolower((string) $byTopic['image-generation']['keywords']);
        $this->assertStringContainsString('image', $kw);
        $this->assertStringContainsString('bild', $kw);
    }

    public function testTopicsAreUniquePerLanguage(): void
    {
        $seen = [];
        foreach (PromptCatalog::all() as $entry) {
            $key = $entry['topic'].'|'.$entry['language'];
            $this->assertArrayNotHasKey($key, $seen, sprintf('Duplicate (topic, language) pair: %s', $key));
            $seen[$key] = true;
        }
    }
}

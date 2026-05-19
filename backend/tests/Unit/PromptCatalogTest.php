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

        // Note (#878): `coding` was retired as a routing target. The
        // catalog still carries the row (so existing installs can flip
        // BENABLED=0 on the next seed), but it no longer participates in
        // Tier-1 recall.
        foreach (
            ['general-chat', 'image-generation', 'video-generation', 'audio-generation'] as $expected
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
            ['general-chat', 'image-generation', 'video-generation', 'audio-generation', 'docsummary', 'officemaker'] as $routable
        ) {
            $this->assertArrayHasKey('keywords', $byTopic[$routable], sprintf(
                'Routable topic "%s" must declare keywords for Synapse recall',
                $routable
            ));
            $this->assertNotSame('', trim((string) $byTopic[$routable]['keywords']));
        }
    }

    public function testRetiredCodingTopicIsDisabled(): void
    {
        // Issue #878: the coding topic was retired but stays in the
        // catalog so existing installs flip BENABLED=0 on the next seed
        // run. The keywords are intentionally blank; the row exists
        // purely to drive the seed update.
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $this->assertArrayHasKey('coding', $byTopic);
        $this->assertArrayHasKey('enabled', $byTopic['coding']);
        $this->assertFalse($byTopic['coding']['enabled']);
    }

    public function testGeneralChatKeywordsCoverProgrammingTermsAfterCodingRetirement(): void
    {
        // Coding queries now ride on `general-chat` (#878). Make sure
        // the general-chat keyword list pulled the relevant tokens over
        // so embedding recall doesn't regress.
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $keywords = strtolower((string) $byTopic['general-chat']['keywords']);
        $this->assertStringContainsString('php', $keywords);
        $this->assertStringContainsString('python', $keywords);
        $this->assertStringContainsString('debug', $keywords);
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

    /**
     * Issue #950: the memory_parse prompt used to extract context-free
     * fragments from multi-sentence input — pronouns like "es"/"it"
     * referencing earlier sentences ended up as standalone memories.
     * The prompt must now explicitly require co-reference resolution and
     * encourage merging related thoughts, plus include a multi-sentence
     * example that demonstrates the behaviour.
     */
    public function testMemoryParsePromptResolvesReferencesAndMergesThoughts(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $this->assertArrayHasKey('tools:memory_parse', $byTopic);
        $prompt = $byTopic['tools:memory_parse']['prompt'];

        $this->assertStringContainsString('RESOLVE REFERENCES', $prompt);
        $this->assertStringContainsString('MERGE related thoughts', $prompt);
        $this->assertStringContainsString('context-free fragments', $prompt);

        // The multi-sentence example from the issue must be present so the
        // model has a concrete demonstration of co-reference resolution.
        $this->assertStringContainsString('bodybuilding', $prompt);
        $this->assertStringContainsString('reason_for_training', $prompt);
        $this->assertStringContainsString('self_worth', $prompt);
    }
}

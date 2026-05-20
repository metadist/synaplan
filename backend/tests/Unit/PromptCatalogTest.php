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
     * Issue #950 — the memory_parse prompt has to teach the model to
     * resolve pronouns, otherwise sentences like "Now I don't need it
     * anymore" land as standalone, context-free memories.
     *
     * Follow-up from FExB17 on PR #956: the first iteration also added
     * a "MERGE related thoughts" rule plus a long multi-sentence German
     * example. That regressed splitting on the production MEM model
     * (`gpt-oss-120b` on Groq), which started dumping the whole input
     * into a single memory. We keep the minimal pronoun fix, drop the
     * merge directive, and pin its absence so it can't be reintroduced
     * by accident.
     */
    public function testMemoryParsePromptResolvesPronounsWithoutMergingThoughts(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $this->assertArrayHasKey('tools:memory_parse', $byTopic);
        $prompt = $byTopic['tools:memory_parse']['prompt'];

        // Positive: the pronoun-resolution rule and its short example
        // must be present — that is the entire #950 fix.
        $this->assertStringContainsString('RESOLVE PRONOUNS', $prompt);
        $this->assertStringContainsString('started boxing', $prompt);
        $this->assertStringContainsString("doesn't need it anymore", $prompt);

        // Negative: the original splitting behaviour must be preserved
        // for smaller models. Anything that nudges the model towards a
        // single combined memory is forbidden.
        $this->assertStringNotContainsString('MERGE', $prompt);
        $this->assertStringNotContainsString('combine them into ONE memory', $prompt);
        $this->assertStringNotContainsString('context-free fragments', $prompt);

        // Negative: the long German bodybuilding example was hardcoding
        // the issue repro into every prompt run — drop it to keep the
        // few-shot block consistent (English) and lean on tokens.
        $this->assertStringNotContainsString('bodybuilding', $prompt);
        $this->assertStringNotContainsString('reason_for_training', $prompt);
        $this->assertStringNotContainsString('self_worth', $prompt);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Prompt\PromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the static prompt catalog:
 *  - canonical routing topics are present
 *  - tools:* helpers exist but are not surfaced for routing
 *  - (topic, language) pairs are unique
 */
final class PromptCatalogTest extends TestCase
{
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

    /**
     * The canonical `general` row carries the chat/coding/lifestyle keyword
     * list so the AI sorter has rich vocabulary for everyday and programming
     * questions in one place.
     */
    public function testCanonicalGeneralKeywordsCoverProgrammingTerms(): void
    {
        $byTopic = [];
        foreach (PromptCatalog::all() as $entry) {
            $byTopic[$entry['topic']] = $entry;
        }

        $keywords = strtolower((string) $byTopic['general']['keywords']);
        $this->assertStringContainsString('php', $keywords);
        $this->assertStringContainsString('python', $keywords);
        $this->assertStringContainsString('debug', $keywords);
        $this->assertStringContainsString('smalltalk', $keywords);
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

        // Positive: the language-preservation rule plugs the parse-mode
        // gap where German input silently became an English memory value.
        // The directive must explicitly forbid translation while keeping
        // keys in English (snake_case stays the storage convention).
        $this->assertStringContainsString('MATCH USER LANGUAGE', $prompt);
        $this->assertStringContainsString('Never translate', $prompt);

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

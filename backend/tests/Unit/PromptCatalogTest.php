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
        $this->assertContains('synaplan', $topics);
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
     * Release defaults for the catch-all `general` topic: MCP data sources ON
     * (a freshly connected server works for normal chat out of the box) and
     * web search on AUTO — the `tool_internet` key must be ABSENT, because an
     * absent key is the tri-state "auto" (classifier decides, manual search
     * toggle keeps working) while a seeded `0` would be a hard disable that
     * beats even the per-message toggle (WebSearchTopicPolicy rule 1).
     */
    public function testGeneralTopicShipsWithMcpOnAndWebSearchAuto(): void
    {
        $general = null;
        foreach (PromptCatalog::all() as $entry) {
            if ('general' === $entry['topic']) {
                $general = $entry;
                break;
            }
        }

        $this->assertNotNull($general);
        $metadata = $general['metadata'] ?? [];
        $this->assertSame('1', $metadata['tool_mcp'] ?? null, 'general must seed tool_mcp=1 (MCP on by default)');
        $this->assertArrayNotHasKey('tool_internet', $metadata, 'general must NOT seed tool_internet — absent = auto (classifier decides)');
    }

    /**
     * Attachment questions split in two: "upload a photo and ask 'what is
     * that?'" is answered FROM the file (BWEBSEARCH=0), while "how much does
     * this cost?" needs live info ABOUT the file's subject (BWEBSEARCH=1 —
     * the pipeline builds the search phrase from the file content, see
     * AttachmentSearchContextResolver). Pin both sides of the rule and the
     * concrete examples smaller models pattern-match on.
     */
    public function testSortPromptRoutesAttachmentQuestions(): void
    {
        $sort = null;
        foreach (PromptCatalog::all() as $entry) {
            if ('tools:sort' === $entry['topic']) {
                $sort = $entry;
                break;
            }
        }

        $this->assertNotNull($sort);
        $prompt = $sort['prompt'];

        $this->assertStringContainsString('Questions answerable from an attached file alone', $prompt);
        $this->assertStringContainsString('Attachment + live information = search', $prompt);
        $this->assertStringContainsString('"What is that?" → general, BWEBSEARCH: 0', $prompt);
        $this->assertStringContainsString('"Was ist das?" → general, BWEBSEARCH: 0', $prompt);
        $this->assertStringContainsString('"How much does this cost?" → general, BWEBSEARCH: 1', $prompt);
    }

    /**
     * The search-query prompt must resolve deictic references against the
     * "Attached file content" block SearchQueryGenerator sends — otherwise
     * "what is that?" + photo searches for the literal words again.
     */
    public function testSearchPromptResolvesAttachmentReferences(): void
    {
        $search = null;
        foreach (PromptCatalog::all() as $entry) {
            if ('tools:search' === $entry['topic']) {
                $search = $entry;
                break;
            }
        }

        $this->assertNotNull($search);
        $prompt = $search['prompt'];

        $this->assertStringContainsString('Attached file content', $prompt);
        $this->assertStringContainsString('NEVER search for the literal question words', $prompt);
        // Worked example: deictic price question + product photo.
        $this->assertStringContainsString('sony wh-1000xm6 price', $prompt);
    }

    public function testSynaplanTopicIsRoutableAndHasPlaceholders(): void
    {
        $synaplan = null;
        $general = null;
        $sort = null;
        $plan = null;
        foreach (PromptCatalog::all() as $entry) {
            if ('synaplan' === $entry['topic']) {
                $synaplan = $entry;
            }
            if ('general' === $entry['topic']) {
                $general = $entry;
            }
            if ('tools:sort' === $entry['topic']) {
                $sort = $entry;
            }
            if ('tools:plan' === $entry['topic']) {
                $plan = $entry;
            }
        }

        $this->assertNotNull($synaplan);
        $this->assertStringStartsNotWith('tools:', $synaplan['topic']);
        $this->assertNotSame('', $synaplan['shortDescription']);
        $this->assertSame(1, substr_count($synaplan['prompt'], '[PLATFORM_CAPABILITIES]'));
        $this->assertSame(1, substr_count($synaplan['prompt'], '[PLATFORM_DOCS]'));

        $this->assertNotNull($general);
        $this->assertSame(1, substr_count($general['prompt'], '[PLATFORM_CAPABILITIES]'));

        $this->assertNotNull($sort);
        $this->assertStringContainsString('Questions about Synaplan itself', $sort['prompt']);
        $this->assertStringContainsString('BTOPIC "synaplan"', $sort['prompt']);

        $this->assertNotNull($plan);
        $this->assertStringContainsString('topic_id: "synaplan"', $plan['prompt']);
        // Engine-off default: the planner still refuses real PDFs. The live
        // OfficePdfRoutingDecorator rewrites this only when OFFICE_CONVERT_URL is set.
        $this->assertStringContainsString('Real PDFs are NOT supported', $plan['prompt']);
    }

    public function testGeneralPromptDoesNotBounceAlreadyPhrasedCreateRequests(): void
    {
        $general = null;
        foreach (PromptCatalog::all() as $entry) {
            if ('general' === $entry['topic']) {
                $general = $entry;
                break;
            }
        }

        $this->assertNotNull($general);
        $this->assertStringContainsString('do not bounce them', $general['prompt']);
        $this->assertStringContainsString('PDF', $general['prompt']);
    }

    public function testOfficeMakerPdfAppendixKeepsBexportOnFollowUpEdits(): void
    {
        $appendix = PromptCatalog::officeMakerPdfExportAppendix();
        $this->assertStringContainsString('earlier in this conversation', $appendix);
        $this->assertStringContainsString('Keep BEXPORT', $appendix);
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

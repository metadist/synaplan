<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AiResponseSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two sanitization passes the service exposes:
 *
 *  - `stripLeakedInstructions()` is applied to every persisted outgoing
 *    message via `Message::sanitizeOutgoingText()`. It must remove the
 *    bracketed system-prompt echoes some smaller LLMs leak (`[Please
 *    reply in German]`, `[Bitte ... auf Deutsch]`, …) WITHOUT touching
 *    `<think>` reasoning blocks (the chat UI still needs those for its
 *    collapsible "Reasoning" panel) or unrelated `[Memory:ID]` badges.
 *
 *  - `stripForDisplay()` is the strict pass used on plain-text surfaces
 *    such as Discord embeds, where the raw text is shown without the
 *    chat UI's `<think>` handling. It must additionally drop reasoning
 *    blocks, including unterminated ones produced by aborted streams.
 */
class AiResponseSanitizerTest extends TestCase
{
    public function testStripLeakedInstructionsRemovesEnglishPleaseReply(): void
    {
        $input = '[Please reply in German] Hallo, wie kann ich helfen?';

        $this->assertSame(
            'Hallo, wie kann ich helfen?',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsRemovesPleaseRespond(): void
    {
        $input = 'Antwort folgt. [Please respond in English]';

        $this->assertSame(
            'Antwort folgt.',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsRemovesGermanBitteVariants(): void
    {
        $input = '[Bitte auf Deutsch antworten] Hallo!';

        $this->assertSame(
            'Hallo!',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsRemovesGermanBitteAntwortenSie(): void
    {
        $input = '[Bitte antworten Sie auf Englisch] Hello there.';

        $this->assertSame(
            'Hello there.',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsKeepsLegitimateGermanBracketedText(): void
    {
        // Without the language-directive anchor a previous version stripped
        // any `[Bitte ...]` annotation, including legitimate UI copy a model
        // might produce.
        $input = '[Bitte hier klicken für Details] Dann öffnet sich der Dialog.';

        $this->assertSame($input, AiResponseSanitizer::stripLeakedInstructions($input));
    }

    public function testStripLeakedInstructionsTrimsWhitespaceLeftBehindByLeadingDirective(): void
    {
        // Persisted via Message::sanitizeOutgoingText() — a leading directive
        // would otherwise persist a stray leading space in the message body.
        $input = '[Please reply in German] Hallo, wie kann ich helfen?';

        $this->assertSame(
            'Hallo, wie kann ich helfen?',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsTrimsWhitespaceLeftBehindByTrailingDirective(): void
    {
        $input = "Antwort folgt.\n[Please respond in English]";

        $this->assertSame(
            'Antwort folgt.',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsPreservesOriginalWhitespaceWhenNothingMatches(): void
    {
        // Pure no-op pass: the persisted text might contain meaningful
        // leading/trailing whitespace (markdown indentation, code fences).
        $input = "  ```php\n  echo 'hi';\n  ```  ";

        $this->assertSame($input, AiResponseSanitizer::stripLeakedInstructions($input));
    }

    public function testStripLeakedInstructionsRemovesBareLanguageDirective(): void
    {
        $input = '[Language: German] Hallo.';

        $this->assertSame(
            'Hallo.',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsKeepsThinkBlocksIntact(): void
    {
        // The chat UI renders `<think>` as a collapsible reasoning panel —
        // dropping it here would silently break that surface.
        $input = '<think>Reasoning here</think>Hallo, wie geht es dir?';

        $this->assertSame($input, AiResponseSanitizer::stripLeakedInstructions($input));
    }

    public function testStripLeakedInstructionsKeepsMemoryBadges(): void
    {
        // Memory badges look bracket-y but are a first-class UI artifact:
        // `MessageText.vue` turns them into clickable chips.
        $input = 'Du heißt Max [Memory:1769617296252930]';

        $this->assertSame($input, AiResponseSanitizer::stripLeakedInstructions($input));
    }

    public function testStripLeakedInstructionsIsCaseInsensitive(): void
    {
        $input = '[PLEASE REPLY IN GERMAN]Hallo';

        $this->assertSame(
            'Hallo',
            AiResponseSanitizer::stripLeakedInstructions($input)
        );
    }

    public function testStripLeakedInstructionsHandlesEmptyString(): void
    {
        $this->assertSame('', AiResponseSanitizer::stripLeakedInstructions(''));
    }

    public function testStripForDisplayRemovesClosedThinkBlock(): void
    {
        $input = '<think>**Providing summary in German**</think>Der Zweite Weltkrieg dauerte von 1939 bis 1945.';

        $this->assertSame(
            'Der Zweite Weltkrieg dauerte von 1939 bis 1945.',
            AiResponseSanitizer::stripForDisplay($input)
        );
    }

    public function testStripForDisplayRemovesUnterminatedThinkBlock(): void
    {
        // Streaming aborted mid-`<think>` — Discord would otherwise show
        // the entire reasoning scratchpad as the message preview.
        $input = '<think>Reasoning that never finished';

        $this->assertSame('', AiResponseSanitizer::stripForDisplay($input));
    }

    public function testStripForDisplayRemovesMultipleThinkBlocks(): void
    {
        $input = '<think>step 1</think>Hello<think>step 2</think> world';

        $this->assertSame('Hello world', AiResponseSanitizer::stripForDisplay($input));
    }

    public function testStripForDisplayCombinesBothPasses(): void
    {
        $input = '<think>plan</think>[Please reply in German] Antwort.';

        $this->assertSame('Antwort.', AiResponseSanitizer::stripForDisplay($input));
    }

    public function testStripForDisplayHandlesEmptyString(): void
    {
        $this->assertSame('', AiResponseSanitizer::stripForDisplay(''));
    }

    public function testStripForDisplayPreservesMarkdown(): void
    {
        // Discord renders markdown — keeping it makes the preview readable.
        $input = '<think>internal</think>**Bold** and `code` in the reply.';

        $this->assertSame(
            '**Bold** and `code` in the reply.',
            AiResponseSanitizer::stripForDisplay($input)
        );
    }
}

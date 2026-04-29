<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AiResponseSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers `stripForDisplay()` — the only method the sanitizer exposes.
 *
 * The historical `stripLeakedInstructions()` regex pass was removed once
 * the language-directive leak ("[Please reply in German]") was fixed at
 * the source via {@see \App\Service\Prompt\LanguageDirectiveBuilder}'s
 * anti-echo clause. See the class-level docblock on `AiResponseSanitizer`
 * for the rationale.
 *
 * What remains here is the genuinely cross-cutting concern: removing
 * `<think>` reasoning blocks before showing a message on a surface that
 * does not have the chat UI's collapsible reasoning panel — e.g. a
 * Discord notification preview.
 */
class AiResponseSanitizerTest extends TestCase
{
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

    public function testStripForDisplayKeepsBracketedTextIntact(): void
    {
        // We deliberately do NOT strip "[Please reply in X]" or
        // "[Bitte ...]" patterns here anymore — that is now prevented at
        // the system-prompt layer. Anything bracketed in the model's
        // visible output is treated as part of the answer and forwarded
        // verbatim to Discord.
        $input = 'Click [here](https://example.com) for [Memory:42] and [Bitte hier klicken].';

        $this->assertSame($input, AiResponseSanitizer::stripForDisplay($input));
    }

    public function testStripForDisplayKeepsLanguageDirectiveLeakIfModelStillEmitsIt(): void
    {
        // If a misbehaving model leaks the directive despite the anti-echo
        // clause, we accept that as visible text rather than relying on a
        // brittle regex. This documents the intentional change.
        $input = '[Please reply in German] Hallo!';

        $this->assertSame($input, AiResponseSanitizer::stripForDisplay($input));
    }

    public function testStripForDisplayTrimsLeadingAndTrailingWhitespace(): void
    {
        $input = "<think>plan</think>\n\n  Antwort.  \n";

        $this->assertSame('Antwort.', AiResponseSanitizer::stripForDisplay($input));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\ClaudeCodeTurnText;
use PHPUnit\Framework\TestCase;

final class ClaudeCodeTurnTextTest extends TestCase
{
    public function testStripsTheAttributionPrefixAndKeepsTheTask(): void
    {
        $raw = "Attribution for git commits and pull requests you create from here on:\n"
            ."Co-Authored-By: Claude <noreply@anthropic.com>\n"
            .'Lies notes.txt und todo.txt und schreibe eine kurze Zusammenfassung.';

        self::assertSame(
            'Lies notes.txt und todo.txt und schreibe eine kurze Zusammenfassung.',
            ClaudeCodeTurnText::visibleRequest($raw),
        );
    }

    public function testDropsATitleSideRequest(): void
    {
        $raw = 'Write the title in the predominant language of the conversation. Reply with JSON.';

        self::assertSame('', ClaudeCodeTurnText::visibleRequest($raw));
    }

    public function testDropsSuggestionMode(): void
    {
        self::assertSame('', ClaudeCodeTurnText::visibleRequest('[SUGGESTION MODE: offer three next steps]'));
    }

    public function testDropsASystemReminderThatHasNoRequest(): void
    {
        $raw = "<system-reminder>\nThe user opened a file.\n</system-reminder>";

        self::assertSame('', ClaudeCodeTurnText::visibleRequest($raw));
    }

    public function testKeepsAnOrdinaryRequest(): void
    {
        self::assertSame('Explain HTTP caching.', ClaudeCodeTurnText::visibleRequest('Explain HTTP caching.'));
    }
}

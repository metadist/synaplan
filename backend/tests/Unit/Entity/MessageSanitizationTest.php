<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Message;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Doctrine lifecycle sanitization on `Message`.
 *
 * The hook only fires inside the entity manager (PrePersist/PreUpdate),
 * so we invoke `sanitizeOutgoingText()` directly. The contract under
 * test is the same one Doctrine sees: outgoing AI messages get their
 * leaked system-prompt notes stripped, incoming user messages are
 * preserved verbatim, and `<think>` reasoning blocks are kept intact
 * because the chat UI still needs them.
 */
class MessageSanitizationTest extends TestCase
{
    public function testOutgoingMessageStripsLeakedInstructionAtStart(): void
    {
        $message = (new Message())
            ->setDirection('OUT')
            ->setText('[Please reply in German] Hallo, wie kann ich helfen?');

        $message->sanitizeOutgoingText();

        $this->assertSame('Hallo, wie kann ich helfen?', trim($message->getText()));
    }

    public function testIncomingMessageIsLeftUntouched(): void
    {
        // Treat user input as data — they may legitimately quote bracketed text.
        $original = 'Spam beispiel: [Please reply in German] — was meinst du?';

        $message = (new Message())
            ->setDirection('IN')
            ->setText($original);

        $message->sanitizeOutgoingText();

        $this->assertSame($original, $message->getText());
    }

    public function testOutgoingMessageKeepsThinkBlocksForFrontend(): void
    {
        // The collapsible reasoning panel in `ChatView.vue` parses
        // `<think>` blocks out of `BTEXT`. Stripping them here would
        // silently break that surface.
        $original = '<think>internal reasoning</think>Antwort.';

        $message = (new Message())
            ->setDirection('OUT')
            ->setText($original);

        $message->sanitizeOutgoingText();

        $this->assertSame($original, $message->getText());
    }

    public function testOutgoingMessageKeepsMemoryBadges(): void
    {
        $original = 'Du heißt Max [Memory:1769617296252930]';

        $message = (new Message())
            ->setDirection('OUT')
            ->setText($original);

        $message->sanitizeOutgoingText();

        $this->assertSame($original, $message->getText());
    }

    public function testEmptyOutgoingMessageRemainsEmpty(): void
    {
        $message = (new Message())->setDirection('OUT');

        $message->sanitizeOutgoingText();

        $this->assertSame('', $message->getText());
    }
}

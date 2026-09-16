<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Service\Message\ConversationSummaryPrompts;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Guards the load-bearing pieces of the production summary prompts — the
 * live eval (`app:summary:eval`) and the worker both build on these exact
 * strings, so silent wording regressions must fail here.
 */
class ConversationSummaryPromptsTest extends TestCase
{
    private const HEADINGS = [
        '## Topic',
        '## User position / goal',
        '## Decisions & constraints',
        '## Open questions',
        '## Already covered / answered',
        '## External results',
    ];

    private function makeMessage(int $id, string $direction, string $text, string $fileText = '', string $fileType = ''): Message&MockObject
    {
        $msg = $this->createMock(Message::class);
        $msg->method('getId')->willReturn($id);
        $msg->method('getDirection')->willReturn($direction);
        $msg->method('getText')->willReturn($text);
        $msg->method('getFileText')->willReturn($fileText);
        $msg->method('getFileType')->willReturn($fileType);

        return $msg;
    }

    public function testBootstrapSystemPromptContainsCapGradientAndHeadings(): void
    {
        $prompt = ConversationSummaryPrompts::bootstrapSystemPrompt(1234);

        self::assertStringContainsString('under 1234 characters', $prompt);
        self::assertStringContainsString('GRADIENT', $prompt);
        self::assertStringContainsString('DO NOT restate', $prompt);
        foreach (self::HEADINGS as $heading) {
            self::assertStringContainsString($heading, $prompt);
        }
    }

    public function testIncrementalSystemPromptContainsCapFoldInstructionAndHeadings(): void
    {
        $prompt = ConversationSummaryPrompts::incrementalSystemPrompt(999);

        self::assertStringContainsString('under 999 characters', $prompt);
        self::assertStringContainsString('PREVIOUS rolling summary', $prompt);
        self::assertStringContainsString('NEWLY AGED-OUT', $prompt);
        foreach (self::HEADINGS as $heading) {
            self::assertStringContainsString($heading, $prompt);
        }
    }

    public function testBootstrapUserContentSegmentsWithGradientHints(): void
    {
        $older = [];
        for ($i = 1; $i <= 9; ++$i) {
            $older[] = $this->makeMessage($i, 0 === $i % 2 ? 'OUT' : 'IN', "turn-{$i}");
        }

        $content = ConversationSummaryPrompts::bootstrapUserContent($older, 3);

        self::assertStringContainsString('## Segment 1 of 3 (oldest — condense aggressively, essentials only):', $content);
        self::assertStringContainsString('## Segment 2 of 3 (middle — condense moderately):', $content);
        self::assertStringContainsString('## Segment 3 of 3 (most recent of the older turns', $content);
        self::assertStringContainsString('[#1 user]: turn-1', $content);
        self::assertStringContainsString('[#2 assistant]: turn-2', $content);
        self::assertStringContainsString('[#9 user]: turn-9', $content);
    }

    public function testBootstrapUserContentSingleTierUsesEssentialsHint(): void
    {
        $content = ConversationSummaryPrompts::bootstrapUserContent(
            [$this->makeMessage(1, 'IN', 'only turn')],
            3,
        );

        self::assertStringContainsString('(condense to the essentials)', $content);
    }

    public function testIncrementalUserContentCombinesPreviousSummaryAndNewMessages(): void
    {
        $content = ConversationSummaryPrompts::incrementalUserContent(
            'PREVIOUS SUMMARY TEXT',
            [$this->makeMessage(42, 'IN', 'new fact')],
        );

        self::assertStringContainsString('## Previous rolling summary', $content);
        self::assertStringContainsString('PREVIOUS SUMMARY TEXT', $content);
        self::assertStringContainsString('## Newly aged-out messages', $content);
        self::assertStringContainsString('[#42 user]: new fact', $content);
    }

    public function testRenderMessageIncludesClippedAttachmentText(): void
    {
        $rendered = ConversationSummaryPrompts::renderMessage(
            $this->makeMessage(7, 'IN', 'see the letter', str_repeat('A', 600), 'document'),
        );

        self::assertStringStartsWith('[#7 user]: see the letter [attached document: ', $rendered);
        // The attachment excerpt is capped at 500 chars (plus ellipsis).
        self::assertLessThan(600, mb_strlen($rendered));
        self::assertStringContainsString('…', $rendered);
    }

    public function testTokenBudgetScalesWithCapAndHasAFloor(): void
    {
        self::assertSame(max(256, (int) ceil(4000 / 3) + 256), ConversationSummaryPrompts::tokenBudget(4000));
        self::assertSame(256, ConversationSummaryPrompts::tokenBudget(0));
    }
}

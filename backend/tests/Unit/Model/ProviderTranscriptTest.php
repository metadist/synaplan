<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ProviderTranscript;
use App\Service\Chat\ProviderTranscriptCapture;
use PHPUnit\Framework\TestCase;

final class ProviderTranscriptTest extends TestCase
{
    public function testRedactsSecretsAndKeepsRoleOrder(): void
    {
        $transcript = ProviderTranscript::fromMessages([
            ['role' => 'system', 'content' => 'Follow the rules. Bearer abc.def'],
            ['role' => 'user', 'content' => 'my key is sk-test-secret'],
            ['role' => 'assistant', 'content' => 'Noted.'],
        ])->redact();

        self::assertSame([
            ['role' => 'instructions', 'text' => 'Follow the rules. …'],
            ['role' => 'you', 'text' => 'my key is …'],
            ['role' => 'assistant', 'text' => 'Noted.'],
        ], $transcript->toArray());
        self::assertFalse($transcript->isTruncated());
    }

    public function testCapsTheWholeTranscript(): void
    {
        $transcript = ProviderTranscript::fromMessages([
            ['role' => 'user', 'content' => str_repeat('a', 100)],
            ['role' => 'assistant', 'content' => str_repeat('b', 100)],
        ])->cap(120);

        $texts = array_map(static fn (array $row): string => $row['text'], $transcript->toArray());
        self::assertSame(120, array_sum(array_map(mb_strlen(...), $texts)));
        self::assertTrue($transcript->isTruncated());
        self::assertSame('you', $transcript->toArray()[0]['role']);
    }

    public function testOpenAiResponsesReductionAndFallbackReplaceTheAssemblerSnapshot(): void
    {
        $capture = new ProviderTranscriptCapture();
        $capture->enable();

        $capture->record([
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'Earlier question'],
            ['role' => 'assistant', 'content' => 'Earlier answer'],
            ['role' => 'user', 'content' => 'Latest question'],
        ]);

        // With previous_response_id, OpenAI reduces input to only the last user turn.
        $capture->recordResponsesRequest([
            'instructions' => 'Be concise.',
            'input' => [
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Latest question']]],
            ],
        ]);

        self::assertSame([
            ['role' => 'instructions', 'text' => 'Be concise.'],
            ['role' => 'you', 'text' => 'Latest question'],
        ], $capture->payload()['messages'] ?? null);

        // When previous_response_id fails, the fallback resends the full history and replaces the reduced attempt.
        $capture->recordResponsesRequest([
            'instructions' => 'Be concise.',
            'input' => [
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Earlier question']]],
                ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Earlier answer']]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Latest question']]],
            ],
        ]);

        self::assertSame([
            ['role' => 'instructions', 'text' => 'Be concise.'],
            ['role' => 'you', 'text' => 'Earlier question'],
            ['role' => 'assistant', 'text' => 'Earlier answer'],
            ['role' => 'you', 'text' => 'Latest question'],
        ], $capture->payload()['messages'] ?? null);
    }

    public function testAnthropicRequestReplacesPreNormalizedMessages(): void
    {
        $capture = new ProviderTranscriptCapture();
        $capture->enable();

        $capture->record([
            ['role' => 'system', 'content' => 'Follow rules.'],
            ['role' => 'assistant', 'content' => 'Leading assistant dropped'],
            ['role' => 'user', 'content' => 'First part'],
            ['role' => 'user', 'content' => 'Second part'],
        ]);

        $capture->recordAnthropicRequest('Follow rules.', [
            ['role' => 'user', 'content' => "First part\n\nSecond part"],
        ]);

        self::assertSame([
            ['role' => 'instructions', 'text' => 'Follow rules.'],
            ['role' => 'you', 'text' => "First part\n\nSecond part"],
        ], $capture->payload()['messages'] ?? null);
    }

    public function testToolLoopAppendsLaterRoundRequests(): void
    {
        $capture = new ProviderTranscriptCapture();
        $capture->enable();

        $capture->record([
            ['role' => 'system', 'content' => 'Edit the sheet.'],
            ['role' => 'user', 'content' => 'Add a total row.'],
        ]);
        $capture->record([
            ['role' => 'system', 'content' => 'Edit the sheet.'],
            ['role' => 'user', 'content' => 'Add a total row.'],
            ['role' => 'tool', 'content' => 'Updated A10'],
        ]);

        $messages = $capture->payload()['messages'] ?? [];
        self::assertCount(5, $messages);
        self::assertSame(['role' => 'assistant', 'text' => 'Updated A10'], $messages[4]);
    }
}

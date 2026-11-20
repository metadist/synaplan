<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunCodec;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use PHPUnit\Framework\TestCase;

final class ChatRunCodecTest extends TestCase
{
    private const RUN_ID = '01JD6Q3Z8Y9X7W6V5T4S3R2Q1P';

    public function testAStoredRunReadsBackTheSame(): void
    {
        $state = new ChatRunState(self::RUN_ID, 7, 11, 5, 'anthropic', 'claude-sonnet-5-5', 'de', 'Base prompt', [['role' => 'user', 'content' => 'Hallo ü']], 100);
        $state->pending = [['id' => 'c1', 'name' => 'custom:order', 'args' => ['n' => 2], 'approval' => 77, 'decision' => ChatRunState::DECISION_APPROVED]];
        $state->plan = [['text' => 'Search', 'state' => ChatRunState::PLAN_DONE]];
        $state->reads = ['r:abc' => 0];
        $state->mode = ChatRunState::MODE_FINISH;
        $state->steps = 3;
        $state->costMicro = 4_200;
        $state->nudges = 1;
        $state->modelFailures = 1;
        $state->waitForApproval();

        $read = (new ChatRunCodec())->decode($state->stored(4, 3, 200, 9_999));

        self::assertInstanceOf(ChatRunState::class, $read);
        self::assertSame([7, 11, 5, 'anthropic', 'claude-sonnet-5-5', 'de', 'Base prompt'], [$read->userId, $read->chatId, $read->incomingMessageId, $read->provider, $read->model, $read->locale, $read->basePrompt]);
        self::assertSame($state->messages, $read->messages);
        self::assertSame($state->pending, $read->pending);
        self::assertSame($state->plan, $read->plan);
        self::assertSame($state->reads, $read->reads);
        self::assertSame(ChatRunStatus::Waiting, $read->status);
        self::assertSame(ChatRunState::WAIT_APPROVAL, $read->waitReason);
        self::assertSame([ChatRunState::MODE_FINISH, 3, 4_200, 1, 1], [$read->mode, $read->steps, $read->costMicro, $read->nudges, $read->modelFailures]);
        self::assertSame([4, 3, 200, 9_999], [$read->expectedSeq(), $read->checkpointSeq(), $read->updatedAt(), $read->expiresAt()]);
    }

    public function testAFinishedRunKeepsItsEndAndAnswer(): void
    {
        $state = new ChatRunState(self::RUN_ID, 7, 11, null, null, null, 'en', '', [], 1);
        $state->finish(ChatRunEnd::Finished, 'The answer.');

        $read = (new ChatRunCodec())->decode($state->stored(2, 1, 5, null));

        self::assertInstanceOf(ChatRunState::class, $read);
        self::assertSame(ChatRunEnd::Finished, $read->end);
        self::assertSame('The answer.', $read->answer);
        self::assertTrue($read->isTerminal());
        self::assertNull($read->incomingMessageId);
    }

    public function testDeeplyNestedToolArgumentsDoNotLoseTheRun(): void
    {
        $args = ['leaf' => true];
        for ($i = 0; $i < 80; ++$i) {
            $args = ['n' => $args];
        }
        $state = new ChatRunState(self::RUN_ID, 7, 11, null, null, null, 'en', '', [], 1);
        $state->pending = [['id' => 'c1', 'name' => 'mcp_deep', 'args' => $args]];

        $read = (new ChatRunCodec())->decode($state->stored(1, 0, 5, null));

        self::assertInstanceOf(ChatRunState::class, $read);
        self::assertSame($state->pending, $read->pending);
    }

    public function testAnotherKindOfRunIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ChatRunCodec())->decode('{"kind":"research","runId":"'.self::RUN_ID.'"}');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function broken(): iterable
    {
        yield 'not json' => ['{'];
        yield 'bad run id' => ['{"kind":"chat","runId":"../../x","userId":7,"chatId":11,"status":"running","messages":[],"basePrompt":"","locale":"en"}'];
        yield 'no owner' => ['{"kind":"chat","runId":"'.self::RUN_ID.'","userId":0,"chatId":11,"status":"running","messages":[],"basePrompt":"","locale":"en"}'];
        yield 'unknown status' => ['{"kind":"chat","runId":"'.self::RUN_ID.'","userId":7,"chatId":11,"status":"approved","messages":[],"basePrompt":"","locale":"en"}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function testABrokenChatRunCountsAsNoRun(string $stored): void
    {
        self::assertNull((new ChatRunCodec())->decode($stored));
    }

    public function testForgedPendingDecisionsAreDropped(): void
    {
        $stored = '{"kind":"chat","runId":"'.self::RUN_ID.'","userId":7,"chatId":11,"status":"running","messages":[],"basePrompt":"","locale":"en",'
            .'"pending":[{"id":"c1","name":"custom:order","args":{},"approval":"77","decision":"yes"}]}';

        $read = (new ChatRunCodec())->decode($stored);

        self::assertInstanceOf(ChatRunState::class, $read);
        self::assertSame([['id' => 'c1', 'name' => 'custom:order', 'args' => []]], $read->pending);
    }
}

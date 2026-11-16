<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Chat;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Chat\ChatModelChoice;
use App\Service\Message\Chat\ChatReply;
use App\Service\Message\Chat\ChatReplyGenerator;
use App\Service\Message\Chat\ChatTurn;
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A provider's safety stop is said as such: the request was declined, and
 * no part of the refused answer is stored as a reply.
 */
final class ChatReplyGeneratorRefusalTest extends TestCase
{
    public function testARefusalWithoutAnyTextIsReportedAsDeclined(): void
    {
        $generator = $this->generator(static function (array $messages, callable $callback): array {
            $callback(['type' => 'finish', 'finish_reason' => 'refusal']);

            return ['provider' => 'anthropic', 'model' => 'claude-fable-5-2', 'usage' => ['prompt_tokens' => 5570, 'completion_tokens' => 0]];
        });
        $reply = $this->streamed($generator);

        $error = $this->rejection(fn () => $generator->assertStreamedAnswer($reply, $this->turn(), $this->model()));

        self::assertSame(ChatFailureReason::ContentFiltered, (new ChatFailureClassifier())->classify($error));
        self::assertSame('refusal', $error->getContext()['block_reason'] ?? null);
        self::assertSame('anthropic', $error->getProviderName());
    }

    public function testTheTextStreamedBeforeARefusalIsNotStored(): void
    {
        $generator = $this->generator(static function (array $messages, callable $callback): array {
            $callback(['type' => 'content', 'content' => '<']);
            $callback(['type' => 'finish', 'finish_reason' => 'refusal']);

            return ['provider' => 'anthropic', 'model' => 'claude-fable-5-2'];
        });
        $reply = $this->streamed($generator);

        self::assertSame('<', $reply->content);
        $error = $this->rejection(fn () => $generator->assertStreamedAnswer($reply, $this->turn(), $this->model()));
        self::assertSame(ChatFailureReason::ContentFiltered, (new ChatFailureClassifier())->classify($error));
    }

    public function testABufferedRefusalIsReportedAsDeclined(): void
    {
        $generator = $this->generator(static fn (): array => []);
        $reply = new ChatReply('', ['provider' => 'anthropic', 'model' => 'claude-fable-5-2'], finishReason: 'refusal');

        $error = $this->rejection(fn () => $generator->settle($reply, $this->turn()));

        self::assertSame(ChatFailureReason::ContentFiltered, (new ChatFailureClassifier())->classify($error));
    }

    public function testAContentFilterStopIsADeclineToo(): void
    {
        $generator = $this->generator(static fn (): array => []);
        $reply = new ChatReply('Here is the first half', ['provider' => 'openai', 'model' => 'gpt-5'], finishReason: 'content_filter');

        $error = $this->rejection(fn () => $generator->settle($reply, $this->turn()));

        self::assertSame('content_filter', $error->getContext()['block_reason'] ?? null);
    }

    public function testAnAnswerThatStoppedCleanlyIsKept(): void
    {
        $generator = $this->generator(static fn (): array => []);
        $reply = new ChatReply('Paris.', ['provider' => 'anthropic', 'model' => 'claude-fable-5-2'], finishReason: 'stop');

        [$content] = $generator->settle($reply, $this->turn());

        self::assertSame('Paris.', $content);
    }

    private function rejection(callable $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }

        self::fail('A refused answer must not be accepted');
    }

    private function streamed(ChatReplyGenerator $generator): ChatReply
    {
        return $generator->stream(
            $this->turn(),
            $this->model(),
            [['role' => 'user', 'content' => 'du kennst meinen namen']],
            [],
            static function (): void {},
            false,
            null,
        );
    }

    private function generator(callable $chatStream): ChatReplyGenerator
    {
        $aiFacade = $this->createStub(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback($chatStream);
        $documents = $this->createStub(ChatDocumentOutput::class);
        $documents->method('tryToolsEdit')->willReturn(null);
        $connection = $this->createStub(Connection::class);
        $connection->method('isConnected')->willReturn(false);

        return new ChatReplyGenerator(
            $aiFacade,
            $documents,
            new NullLogger(),
            new ProviderTranscriptCapture(),
            new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $connection]), new NullLogger()),
        );
    }

    private function turn(): ChatTurn
    {
        $message = (new Message())->setUserId(7)->setText('du kennst meinen namen');

        return new ChatTurn($message, [], [], [], null, null, new PerfTimer());
    }

    private function model(): ChatModelChoice
    {
        return new ChatModelChoice(431, 'anthropic', 'claude-fable-5-2', 7, false, false, false);
    }
}

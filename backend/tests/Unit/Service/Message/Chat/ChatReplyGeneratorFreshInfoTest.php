<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Chat;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Chat\ChatModelChoice;
use App\Service\Message\Chat\ChatReplyGenerator;
use App\Service\Message\Chat\ChatTurn;
use App\Service\Message\Chat\FreshInfoRequest;
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A streamed answer that is the model's request for a web search never
 * reaches the person; an ordinary answer streams as before.
 */
final class ChatReplyGeneratorFreshInfoTest extends TestCase
{
    public function testARequestIsSwallowedAndReported(): void
    {
        $aiFacade = $this->createStub(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback(static function (array $messages, callable $callback): array {
            $callback(['type' => 'content', 'content' => '[[SEARCH_']);
            $callback(['type' => 'content', 'content' => 'WEB: current CEO of Siemens]]']);

            return ['provider' => 'test', 'model' => 'test-model', 'usage' => ['total_tokens' => 9]];
        });
        $streamed = [];

        $reply = $this->generator($aiFacade)->stream(
            $this->turn([FreshInfoRequest::OPTION => true]),
            $this->model(),
            [['role' => 'user', 'content' => 'Who runs Siemens?']],
            [],
            static function (string|array $chunk) use (&$streamed): void {
                $streamed[] = $chunk;
            },
            false,
            null,
        );

        self::assertSame([], $streamed);
        self::assertSame('current CEO of Siemens', $reply->webSearchQuery);
    }

    public function testWithoutTheOptionTheMarkerIsJustText(): void
    {
        $aiFacade = $this->createStub(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback(static function (array $messages, callable $callback): array {
            $callback('[[SEARCH_WEB: x]]');

            return ['provider' => 'test', 'model' => 'test-model', 'usage' => []];
        });
        $streamed = [];

        $reply = $this->generator($aiFacade)->stream(
            $this->turn([]),
            $this->model(),
            [['role' => 'user', 'content' => 'Hi']],
            [],
            static function (string|array $chunk) use (&$streamed): void {
                $streamed[] = $chunk;
            },
            false,
            null,
        );

        self::assertSame(['[[SEARCH_WEB: x]]'], $streamed);
        self::assertNull($reply->webSearchQuery);
    }

    public function testAnOrdinaryAnswerStreamsWithTheOptionOn(): void
    {
        $aiFacade = $this->createStub(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback(static function (array $messages, callable $callback): array {
            $callback(['type' => 'reasoning', 'content' => 'hm']);
            $callback(['type' => 'content', 'content' => 'Paris']);
            $callback(['type' => 'content', 'content' => '.']);

            return ['provider' => 'test', 'model' => 'test-model', 'usage' => []];
        });
        $streamed = [];

        $reply = $this->generator($aiFacade)->stream(
            $this->turn([FreshInfoRequest::OPTION => true]),
            $this->model(),
            [['role' => 'user', 'content' => 'Capital of France?']],
            [],
            static function (string|array $chunk) use (&$streamed): void {
                $streamed[] = $chunk;
            },
            false,
            null,
        );

        self::assertSame([
            ['type' => 'reasoning', 'content' => 'hm'],
            ['type' => 'content', 'content' => 'Paris'],
            ['type' => 'content', 'content' => '.'],
        ], $streamed);
        self::assertSame('Paris.', $reply->content);
        self::assertNull($reply->webSearchQuery);
    }

    private function generator(AiFacade $aiFacade): ChatReplyGenerator
    {
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

    /**
     * @param array<string, mixed> $options
     */
    private function turn(array $options): ChatTurn
    {
        $message = (new Message())->setUserId(7)->setText('Hi');

        return new ChatTurn($message, [], [], $options, null, null, new PerfTimer());
    }

    private function model(): ChatModelChoice
    {
        return new ChatModelChoice(1, 'test', 'test-model', 7, false, false, false);
    }
}

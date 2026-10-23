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
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A streamed chat answer keeps the PHP worker waiting on the provider for its
 * whole length; the worker must not hold a database connection meanwhile.
 */
final class ChatReplyGeneratorConnectionReleaseTest extends TestCase
{
    private bool $closed = false;

    public function testTheConnectionIsGivenBackBeforeTheProviderStreamStarts(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects(self::once())->method('chatStream')
            ->willReturnCallback(function (array $messages, callable $callback): array {
                self::assertTrue($this->closed, 'the connection is still open when the provider stream starts');
                $callback('Hello');

                return ['provider' => 'test', 'model' => 'test-model', 'usage' => []];
            });

        $reply = $this->generator($aiFacade, $this->connection(expectClose: true))
            ->stream($this->turn(), $this->model(), [['role' => 'user', 'content' => 'Hi']], [], static function (): void {}, false, null);

        self::assertSame('Hello', $reply->content);
    }

    public function testABufferedAnswerKeepsItsConnection(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chat')->willReturn(['content' => 'Hello', 'provider' => 'test', 'model' => 'test-model']);
        $aiFacade->expects(self::never())->method('chatStream');

        $this->generator($aiFacade, $this->connection(expectClose: false))
            ->complete($this->turn(), $this->model(), [['role' => 'user', 'content' => 'Hi']], [], [], null);
    }

    private function generator(AiFacade $aiFacade, Connection $connection): ChatReplyGenerator
    {
        $documents = $this->createStub(ChatDocumentOutput::class);
        $documents->method('tryToolsEdit')->willReturn(null);

        return new ChatReplyGenerator(
            $aiFacade,
            $documents,
            new NullLogger(),
            new ProviderTranscriptCapture(),
            new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $connection]), new NullLogger()),
        );
    }

    private function connection(bool $expectClose): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturnCallback(fn (): bool => !$this->closed);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($expectClose ? self::once() : self::never())->method('close')
            ->willReturnCallback(function (): void {
                $this->closed = true;
            });

        return $connection;
    }

    private function turn(): ChatTurn
    {
        $message = (new Message())->setUserId(7)->setText('Hi');

        return new ChatTurn($message, [], [], [], null, null, new PerfTimer());
    }

    private function model(): ChatModelChoice
    {
        return new ChatModelChoice(1, 'test', 'test-model', 7, false, false, false);
    }
}

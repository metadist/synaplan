<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\ChatSummaryRepository;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\MessageRepository;
use App\Service\Iam\Erasure\ConversationEraser;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Message\ConversationSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Chats, messages and summary rows go inside the transaction; the summaries'
 * Redis copies are evicted only once the rows are committed away.
 */
final class ConversationEraserTest extends TestCase
{
    public function testRemovesTheConversationRowsAndEvictsTheCachedSummariesAfterTheCommit(): void
    {
        $chats = [$this->chat(5), $this->chat(6)];
        $message = new Message();
        $chatRepository = $this->createStub(ChatRepository::class);
        $chatRepository->method('findBy')->willReturn($chats);
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findBy')->willReturn([$message]);
        $attempts = $this->createStub(EmailVerificationAttemptRepository::class);
        $attempts->method('findBy')->willReturn([]);
        $chatSummaries = $this->createMock(ChatSummaryRepository::class);
        $chatSummaries->expects(self::once())->method('deleteByUser')->with(42);
        $summaries = $this->createStub(ConversationSummaryService::class);
        $evicted = [];
        $summaries->method('evictCached')->willReturnCallback(static function (array $chatIds) use (&$evicted): void {
            $evicted[] = $chatIds;
        });
        $removed = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$removed): void {
            $removed[] = $row;
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new ConversationEraser($em, $chatRepository, $messages, $chatSummaries, $summaries, $attempts))->erase($erasure);

        self::assertSame([...$chats, $message], $removed);
        self::assertSame([], $evicted, 'The cache may only be evicted after the commit.');
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
        self::assertSame([[5, 6]], $evicted);
    }

    private function chat(int $id): Chat
    {
        $chat = new Chat();
        (new \ReflectionProperty(Chat::class, 'id'))->setValue($chat, $id);

        return $chat;
    }
}

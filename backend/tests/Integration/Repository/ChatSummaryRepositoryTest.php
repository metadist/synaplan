<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Chat;
use App\Repository\ChatSummaryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A summary is stored only while its chat exists and belongs to the user, so
 * a refresh that outlives the chat's or the account's deletion writes
 * nothing back.
 */
final class ChatSummaryRepositoryTest extends KernelTestCase
{
    private const OWNER_ID = 987_654_321;
    private const STRANGER_ID = 987_654_322;

    private EntityManagerInterface $em;
    private ChatSummaryRepository $summaries;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->summaries = static::getContainer()->get(ChatSummaryRepository::class);
    }

    public function testTheSummaryOfAnOwnedChatIsStoredAndUpdated(): void
    {
        $chatId = $this->persistChat();

        self::assertTrue($this->upsert($chatId, self::OWNER_ID, 'First summary'));
        self::assertTrue($this->upsert($chatId, self::OWNER_ID, 'Second summary'));

        self::assertSame('Second summary', $this->summaries->findOneByChatId($chatId)?->getSummary());
    }

    public function testNothingIsStoredForAChatThatWasDeleted(): void
    {
        $chatId = $this->persistChat();
        $this->em->remove($this->em->getReference(Chat::class, $chatId));
        $this->em->flush();

        self::assertFalse($this->upsert($chatId, self::OWNER_ID, 'Summary of a deleted chat'));

        self::assertNull($this->summaries->findOneByChatId($chatId));
    }

    public function testNothingIsStoredForSomeoneElsesChat(): void
    {
        $chatId = $this->persistChat();

        self::assertFalse($this->upsert($chatId, self::STRANGER_ID, 'Summary written for the wrong user'));

        self::assertNull($this->summaries->findOneByChatId($chatId));
    }

    private function persistChat(): int
    {
        $chat = (new Chat())->setUserId(self::OWNER_ID)->setTitle('Summarised chat')->setSource('web');
        $this->em->persist($chat);
        $this->em->flush();

        return (int) $chat->getId();
    }

    private function upsert(int $chatId, int $userId, string $summary): bool
    {
        return $this->summaries->upsert($chatId, $userId, $summary, 42, 10, 'fingerprint');
    }
}

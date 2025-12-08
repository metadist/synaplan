<?php

namespace App\Tests\Repository;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for MessageRepository.
 *
 * Focus on findChatHistory() with intelligent limit logic
 */
class MessageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MessageRepository $repository;
    private ?User $testUser = null;
    private ?Chat $testChat = null;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->repository = $this->em->getRepository(Message::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setMail('test_'.time().'@test.com');
        $this->testUser->setPw('test123');
        $this->testUser->setProviderId('WEB');
        $this->testUser->setUserLevel('NEW');
        $this->em->persist($this->testUser);
        $this->em->flush(); // Flush user first to get ID

        // Create test chat (now user has ID)
        $this->testChat = new Chat();
        $this->testChat->setUserId($this->testUser->getId());
        $this->testChat->setTitle('Test Chat');
        $this->em->persist($this->testChat);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->testChat) {
            $messages = $this->repository->findBy(['chatId' => $this->testChat->getId()]);
            foreach ($messages as $message) {
                $this->em->remove($message);
            }
            $this->em->remove($this->testChat);
        }

        if ($this->testUser) {
            $this->em->remove($this->testUser);
        }

        $this->em->flush();

        parent::tearDown();
    }

    public function testFindChatHistoryReturnsMessagesFromSpecificChat(): void
    {
        // Create messages in our test chat
        $this->createTestMessage('Message 1', 100);
        $this->createTestMessage('Message 2', 200);
        $this->createTestMessage('Message 3', 300);

        // Create messages in a different chat (should not be returned)
        $otherChat = new Chat();
        $otherChat->setUserId($this->testUser->getId());
        $otherChat->setTitle('Other Chat');
        $this->em->persist($otherChat);
        $this->em->flush();

        $otherMessage = new Message();
        $otherMessage->setUserId($this->testUser->getId());
        $otherMessage->setChat($otherChat); // Use setChat() instead of setChatId()
        $otherMessage->setTrackingId(999);
        $otherMessage->setUnixTimestamp(400);
        $otherMessage->setDateTime(date('YmdHis'));
        $otherMessage->setText('Other chat message');
        $otherMessage->setDirection('IN');
        $otherMessage->setProviderIndex('WEB');
        $otherMessage->setMessageType('TEST');
        $otherMessage->setTopic('CHAT');
        $otherMessage->setLanguage('en');
        $otherMessage->setStatus('complete');
        $this->em->persist($otherMessage);
        $this->em->flush();

        // Get chat history
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId()
        );

        $this->assertCount(3, $history, 'Should return exactly 3 messages from test chat');
        $this->assertEquals('Message 1', $history[0]->getText());
        $this->assertEquals('Message 2', $history[1]->getText());
        $this->assertEquals('Message 3', $history[2]->getText());

        // Clean up
        $this->em->remove($otherChat);
        $this->em->remove($otherMessage);
        $this->em->flush();
    }

    public function testFindChatHistoryRespectsMaxMessages(): void
    {
        // Create 40 short messages
        for ($i = 1; $i <= 40; ++$i) {
            $this->createTestMessage("Message {$i}", $i * 10);
        }

        // Request max 30 messages
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId(),
            30  // maxMessages
        );

        $this->assertCount(30, $history, 'Should return max 30 messages');
        // Should return newest 30 messages (11-40) in chronological order
        $this->assertEquals('Message 11', $history[0]->getText());
        $this->assertEquals('Message 40', $history[29]->getText());
    }

    public function testFindChatHistoryRespectsMaxTotalChars(): void
    {
        // Create messages with known lengths
        // Each message is exactly 100 chars long (including "Message X: " prefix)
        for ($i = 1; $i <= 20; ++$i) {
            $baseText = "Message {$i}: ";
            $padding = str_repeat('x', 85 - strlen($baseText));
            $this->createTestMessage($baseText.$padding, $i * 10);
        }

        // Request with 500 char limit (should fit ~5 messages)
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId(),
            30,      // maxMessages
            500      // maxTotalChars
        );

        $this->assertLessThanOrEqual(6, count($history), 'Should return ~5 messages within 500 chars');

        // Verify total chars is under limit
        $totalChars = 0;
        foreach ($history as $msg) {
            $totalChars += strlen($msg->getText());
        }
        $this->assertLessThanOrEqual(500, $totalChars, 'Total chars should not exceed limit');
    }

    public function testFindChatHistoryIncludesFileTextInCharCount(): void
    {
        // Create message with large file text
        $message = new Message();
        $message->setUserId($this->testUser->getId());
        $message->setChat($this->testChat); // Use setChat()
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText('Short text');  // 10 chars
        $message->setFileText(str_repeat('x', 1000));  // 1000 chars
        $message->setDirection('IN');
        $message->setProviderIndex('WEB');
        $message->setMessageType('TEST');
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setStatus('complete');
        $this->em->persist($message);

        // Create second message
        $message2 = new Message();
        $message2->setUserId($this->testUser->getId());
        $message2->setChat($this->testChat); // Use setChat()
        $message2->setTrackingId(time());
        $message2->setUnixTimestamp(time() + 1);
        $message2->setDateTime(date('YmdHis'));
        $message2->setText('Another message');
        $message2->setDirection('IN');
        $message2->setProviderIndex('WEB');
        $message2->setMessageType('TEST');
        $message2->setTopic('CHAT');
        $message2->setLanguage('en');
        $message2->setStatus('complete');
        $this->em->persist($message2);

        $this->em->flush();

        // Request with 500 char limit - should only return newest message
        // because first message is 1010 chars (text + fileText)
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId(),
            30,
            500
        );

        $this->assertCount(1, $history, 'Should only return 1 message within char limit');
        $this->assertEquals('Another message', $history[0]->getText());
    }

    public function testFindChatHistoryAlwaysReturnsAtLeastOneMessage(): void
    {
        // Create one huge message (exceeds char limit)
        $this->createTestMessage(str_repeat('x', 20000), time());

        // Request with small char limit
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId(),
            30,
            100  // Very small limit
        );

        $this->assertCount(1, $history, 'Should always return at least 1 message');
    }

    public function testFindChatHistoryReturnsOldestFirst(): void
    {
        $this->createTestMessage('First', 100);
        $this->createTestMessage('Second', 200);
        $this->createTestMessage('Third', 300);

        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId()
        );

        $this->assertEquals('First', $history[0]->getText());
        $this->assertEquals('Second', $history[1]->getText());
        $this->assertEquals('Third', $history[2]->getText());
    }

    public function testFindChatHistoryWithEmptyChat(): void
    {
        $history = $this->repository->findChatHistory(
            $this->testUser->getId(),
            $this->testChat->getId()
        );

        $this->assertIsArray($history);
        $this->assertEmpty($history);
    }

    public function testFindChatHistoryWithWrongUserId(): void
    {
        $this->createTestMessage('Test message', time());

        // Try to get chat history with wrong user ID
        $history = $this->repository->findChatHistory(
            999999,  // Non-existent user
            $this->testChat->getId()
        );

        $this->assertEmpty($history, 'Should return empty array for wrong user');
    }

    /**
     * Helper to create test message.
     */
    private function createTestMessage(string $text, int $timestamp): Message
    {
        $message = new Message();
        $message->setUserId($this->testUser->getId());
        $message->setChat($this->testChat); // Use setChat() instead of setChatId()
        $message->setTrackingId(time());
        $message->setUnixTimestamp($timestamp);
        $message->setDateTime(date('YmdHis', $timestamp));
        $message->setText($text);
        $message->setDirection('IN');
        $message->setProviderIndex('WEB');
        $message->setMessageType('TEST');
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setStatus('complete');

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}

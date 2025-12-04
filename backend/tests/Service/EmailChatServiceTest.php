<?php

namespace App\Tests\Service;

use App\Service\EmailChatService;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailChatServiceTest extends TestCase
{
    private EmailChatService $emailChatService;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;
    private ChatRepository $chatRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->chatRepository = $this->createMock(ChatRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->emailChatService = new EmailChatService(
            $this->em,
            $this->userRepository,
            $this->chatRepository,
            $this->logger
        );
    }

    public function testParseEmailKeyword_WithKeyword(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart+mybot@synaplan.com');
        $this->assertEquals('mybot', $keyword);
    }

    public function testParseEmailKeyword_WithoutKeyword(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart@synaplan.com');
        $this->assertNull($keyword);
    }

    public function testParseEmailKeyword_InvalidFormat(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('test@example.com');
        $this->assertNull($keyword);
    }

    public function testParseEmailKeyword_WithHyphenAndUnderscore(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart+my-bot_123@synaplan.com');
        $this->assertEquals('my-bot_123', $keyword);
    }

    public function testGetUserPersonalEmailAddress_WithKeyword(): void
    {
        $user = new User();
        $user->setUserDetails(['email_keyword' => 'mybot']);

        $email = $this->emailChatService->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart+mybot@synaplan.com', $email);
    }

    public function testGetUserPersonalEmailAddress_WithoutKeyword(): void
    {
        $user = new User();
        $user->setUserDetails([]);

        $email = $this->emailChatService->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart@synaplan.com', $email);
    }
}

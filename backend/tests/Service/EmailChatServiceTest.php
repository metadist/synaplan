<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\EmailChatService;
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

    public function testParseEmailKeywordWithKeyword(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart+mybot@synaplan.net');
        $this->assertEquals('mybot', $keyword);
    }

    public function testParseEmailKeywordWithoutKeyword(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart@synaplan.net');
        $this->assertNull($keyword);
    }

    public function testParseEmailKeywordInvalidFormat(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('test@example.com');
        $this->assertNull($keyword);
    }

    public function testParseEmailKeywordWithHyphenAndUnderscore(): void
    {
        $keyword = $this->emailChatService->parseEmailKeyword('smart+my-bot_123@synaplan.net');
        $this->assertEquals('my-bot_123', $keyword);
    }

    public function testGetUserPersonalEmailAddressWithKeyword(): void
    {
        $user = new User();
        $user->setUserDetails(['email_keyword' => 'mybot']);

        $email = $this->emailChatService->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart+mybot@synaplan.net', $email);
    }

    public function testGetUserPersonalEmailAddressWithoutKeyword(): void
    {
        $user = new User();
        $user->setUserDetails([]);

        $email = $this->emailChatService->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart@synaplan.net', $email);
    }
}

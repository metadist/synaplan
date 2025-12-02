<?php

namespace App\Tests\Service;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\EmailBlacklistRepository;
use App\Repository\UserRepository;
use App\Service\EmailChatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailChatServiceTest extends TestCase
{
    private EmailChatService $service;
    private EntityManagerInterface $em;
    private UserRepository $userRepository;
    private ChatRepository $chatRepository;
    private EmailBlacklistRepository $blacklistRepository;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->chatRepository = $this->createMock(ChatRepository::class);
        $this->blacklistRepository = $this->createMock(EmailBlacklistRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new EmailChatService(
            $this->em,
            $this->userRepository,
            $this->chatRepository,
            $this->blacklistRepository,
            $this->logger
        );
    }

    public function testParseEmailKeyword_WithKeyword(): void
    {
        $result = $this->service->parseEmailKeyword('smart+project@synaplan.com');
        $this->assertEquals('project', $result);
    }

    public function testParseEmailKeyword_WithComplexKeyword(): void
    {
        $result = $this->service->parseEmailKeyword('smart+test-123@synaplan.com');
        $this->assertEquals('test-123', $result);
    }

    public function testParseEmailKeyword_WithoutKeyword(): void
    {
        $result = $this->service->parseEmailKeyword('smart@synaplan.com');
        $this->assertNull($result);
    }

    public function testParseEmailKeyword_InvalidFormat(): void
    {
        $result = $this->service->parseEmailKeyword('other@example.com');
        $this->assertNull($result);
    }

    public function testFindOrCreateUserFromEmail_BlacklistedEmail(): void
    {
        $email = 'spammer@evil.com';

        $this->blacklistRepository->expects($this->once())
            ->method('isBlacklisted')
            ->with($email)
            ->willReturn(true);

        $result = $this->service->findOrCreateUserFromEmail($email);

        $this->assertNull($result['user']);
        $this->assertTrue($result['blacklisted']);
        $this->assertEquals('Email address is blacklisted', $result['error']);
    }

    public function testFindOrCreateUserFromEmail_RegisteredUser(): void
    {
        $email = 'user@example.com';
        $user = $this->createMock(User::class);

        $this->blacklistRepository->expects($this->once())
            ->method('isBlacklisted')
            ->with($email)
            ->willReturn(false);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['mail' => $email])
            ->willReturn($user);

        $result = $this->service->findOrCreateUserFromEmail($email);

        $this->assertSame($user, $result['user']);
        $this->assertFalse($result['is_anonymous']);
        $this->assertFalse($result['blacklisted']);
    }

    /**
     * Note: Anonymous user creation is complex and requires integration testing
     * This test is skipped for unit testing due to QueryBuilder mocking complexity
     */
    public function testFindOrCreateUserFromEmail_CreatesAnonymousUser_SkippedForComplexity(): void
    {
        $this->markTestSkipped('Complex QueryBuilder mocking - tested in integration tests');
    }

    public function testFindOrCreateChatContext_WithKeyword(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $keyword = 'project';

        $this->chatRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'userId' => 1,
                'title' => 'Email: project'
            ])
            ->willReturn(null);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Chat::class));

        $this->em->expects($this->once())
            ->method('flush');

        $chat = $this->service->findOrCreateChatContext($user, $keyword, null, null);

        $this->assertInstanceOf(Chat::class, $chat);
    }

    public function testFindOrCreateChatContext_WithExistingKeywordChat(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $keyword = 'support';
        $existingChat = $this->createMock(Chat::class);

        $this->chatRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'userId' => 1,
                'title' => 'Email: support'
            ])
            ->willReturn($existingChat);

        $this->em->expects($this->never())
            ->method('persist');

        $chat = $this->service->findOrCreateChatContext($user, $keyword, null, null);

        $this->assertSame($existingChat, $chat);
    }

    public function testFindOrCreateChatContext_WithoutKeyword_CreatesGeneral(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->chatRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'userId' => 1,
                'title' => 'Email Conversation'
            ])
            ->willReturn(null);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Chat::class));

        $this->em->expects($this->once())
            ->method('flush');

        $chat = $this->service->findOrCreateChatContext($user, null, null, null);

        $this->assertInstanceOf(Chat::class, $chat);
    }

    public function testGetUserEmailKeyword(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUserDetails')
            ->willReturn(['email_keyword' => 'myproject']);

        $keyword = $this->service->getUserEmailKeyword($user);
        $this->assertEquals('myproject', $keyword);
    }

    public function testGetUserEmailKeyword_NotSet(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUserDetails')
            ->willReturn([]);

        $keyword = $this->service->getUserEmailKeyword($user);
        $this->assertNull($keyword);
    }

    public function testSetUserEmailKeyword(): void
    {
        $user = $this->createMock(User::class);
        $user->expects($this->once())
            ->method('getUserDetails')
            ->willReturn([]);
        
        $user->expects($this->once())
            ->method('setUserDetails')
            ->with($this->callback(function ($details) {
                $this->assertEquals('test-keyword', $details['email_keyword']);
                return true;
            }));

        $this->em->expects($this->once())
            ->method('flush');

        $this->service->setUserEmailKeyword($user, 'test-keyword');
    }

    public function testSetUserEmailKeyword_SanitizesInput(): void
    {
        $user = $this->createMock(User::class);
        $user->expects($this->once())
            ->method('getUserDetails')
            ->willReturn([]);
        
        $user->expects($this->once())
            ->method('setUserDetails')
            ->with($this->callback(function ($details) {
                // Should remove invalid characters and lowercase
                $this->assertEquals('test123', $details['email_keyword']);
                return true;
            }));

        $this->em->expects($this->once())
            ->method('flush');

        $this->service->setUserEmailKeyword($user, 'Test@123!');
    }

    public function testSetUserEmailKeyword_ThrowsOnInvalidKeyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid keyword format');

        $user = $this->createMock(User::class);
        $user->method('getUserDetails')->willReturn([]);

        $this->service->setUserEmailKeyword($user, '@@@!!!');
    }

    public function testGetUserPersonalEmailAddress_WithKeyword(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUserDetails')
            ->willReturn(['email_keyword' => 'myproject']);

        $email = $this->service->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart+myproject@synaplan.com', $email);
    }

    public function testGetUserPersonalEmailAddress_WithoutKeyword(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getUserDetails')
            ->willReturn([]);

        $email = $this->service->getUserPersonalEmailAddress($user);
        $this->assertEquals('smart@synaplan.com', $email);
    }
}


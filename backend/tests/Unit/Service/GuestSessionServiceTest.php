<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;
use App\Service\GuestSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class GuestSessionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private GuestSessionRepository&MockObject $sessionRepository;
    private UserRepository&MockObject $userRepository;
    private LoggerInterface&MockObject $logger;
    private GuestSessionService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->sessionRepository = $this->createMock(GuestSessionRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new GuestSessionService(
            $this->em,
            $this->sessionRepository,
            $this->userRepository,
            $this->logger,
        );
    }

    public function testCreateSessionWithCloudflareHeaders(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '203.0.113.42');
        $request->headers->set('CF-IPCountry', 'DE');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->createSession('test-uuid-123', $request);

        $this->assertSame('test-uuid-123', $session->getSessionId());
        $this->assertSame('203.0.113.42', $session->getIpAddress());
        $this->assertSame('DE', $session->getCountry());
        $this->assertSame(5, $session->getMaxMessages());
        $this->assertSame(0, $session->getMessageCount());
    }

    public function testCreateSessionWithoutCloudflareHeaders(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->createSession('fallback-uuid', $request);

        $this->assertSame('127.0.0.1', $session->getIpAddress());
        $this->assertNull($session->getCountry());
    }

    public function testCreateSessionFiltersTorCountry(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');
        $request->headers->set('CF-IPCountry', 'T1');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->createSession('tor-uuid', $request);

        $this->assertNull($session->getCountry());
    }

    public function testGetSessionDelegatesToRepository(): void
    {
        $expectedSession = new GuestSession();
        $expectedSession->setSessionId('find-me');

        $this->sessionRepository->expects($this->once())
            ->method('findBySessionId')
            ->with('find-me')
            ->willReturn($expectedSession);

        $result = $this->service->getSession('find-me');

        $this->assertSame($expectedSession, $result);
    }

    public function testGetSessionReturnsNullWhenNotFound(): void
    {
        $this->sessionRepository->expects($this->once())
            ->method('findBySessionId')
            ->willReturn(null);

        $result = $this->service->getSession('nonexistent');

        $this->assertNull($result);
    }

    public function testCheckLimitReturnsTrueWhenUnderLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(3);

        $this->assertTrue($this->service->checkLimit($session));
    }

    public function testCheckLimitReturnsFalseWhenAtLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(5);

        $this->assertFalse($this->service->checkLimit($session));
    }

    public function testCheckLimitReturnsFalseWhenOverLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(7);

        $this->assertFalse($this->service->checkLimit($session));
    }

    public function testIncrementCountFlushesEntityManager(): void
    {
        $session = new GuestSession();
        $session->setMessageCount(2);

        $this->em->expects($this->once())->method('flush');

        $this->service->incrementCount($session);

        $this->assertSame(3, $session->getMessageCount());
    }

    public function testGetRemainingMessages(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(2);

        $this->assertSame(3, $this->service->getRemainingMessages($session));
    }

    public function testGetProcessingUserReturnsAdminUser(): void
    {
        $adminUser = new User();
        $reflection = new \ReflectionProperty($adminUser, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($adminUser, 1);

        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['userLevel' => 'ADMIN'])
            ->willReturn($adminUser);

        $result = $this->service->getProcessingUser();

        $this->assertSame($adminUser, $result);
    }

    public function testGetProcessingUserReturnsNullAndLogsWhenNoAdmin(): void
    {
        $this->userRepository->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('No admin user found'));

        $result = $this->service->getProcessingUser();

        $this->assertNull($result);
    }

    public function testAttachChatSetsIdAndFlushes(): void
    {
        $session = new GuestSession();
        $this->assertNull($session->getChatId());

        $this->em->expects($this->once())->method('flush');

        $this->service->attachChat($session, 42);

        $this->assertSame(42, $session->getChatId());
    }

    public function testAttachChatSkipsFlushWhenAlreadyAttached(): void
    {
        $session = new GuestSession();
        $session->setChatId(42);

        $this->em->expects($this->never())->method('flush');

        $this->service->attachChat($session, 42);
    }
}

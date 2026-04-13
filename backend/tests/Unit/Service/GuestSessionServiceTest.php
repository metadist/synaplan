<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;
use App\Service\GuestSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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

        $this->sessionRepository->method('countActiveSessionsByIp')->willReturn(0);
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

        $this->sessionRepository->method('countActiveSessionsByIp')->willReturn(0);
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

        $this->sessionRepository->method('countActiveSessionsByIp')->willReturn(0);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->createSession('tor-uuid', $request);

        $this->assertNull($session->getCountry());
    }

    public function testCreateSessionThrowsLogicExceptionOnDuplicateId(): void
    {
        $existing = new GuestSession();
        $existing->setSessionId('duplicate-uuid');

        $this->sessionRepository->expects($this->once())
            ->method('findBySessionId')
            ->with('duplicate-uuid')
            ->willReturn($existing);

        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session ID already exists');

        $this->service->createSession('duplicate-uuid', $request);
    }

    public function testCreateSessionThrowsOverflowWhenIpLimitExceeded(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $this->sessionRepository->expects($this->once())
            ->method('countActiveSessionsByIp')
            ->with('1.2.3.4')
            ->willReturn(5);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('IP rate limit exceeded'),
                $this->callback(fn (array $ctx) => '1.2.3.4' === $ctx['ip'] && 5 === $ctx['active_sessions'])
            );

        $this->expectException(\OverflowException::class);

        $this->service->createSession('rate-limited-uuid', $request);
    }

    public function testCreateSessionAllowsWhenUnderIpLimit(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $this->sessionRepository->expects($this->once())
            ->method('countActiveSessionsByIp')
            ->with('1.2.3.4')
            ->willReturn(4);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $session = $this->service->createSession('allowed-uuid', $request);

        $this->assertSame('allowed-uuid', $session->getSessionId());
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

        $this->mockUserQueryBuilder($adminUser);

        $result = $this->service->getProcessingUser();

        $this->assertSame($adminUser, $result);
    }

    public function testGetProcessingUserReturnsNullAndLogsWhenNoAdmin(): void
    {
        $this->mockUserQueryBuilder(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('No admin user found'));

        $result = $this->service->getProcessingUser();

        $this->assertNull($result);
    }

    public function testGetProcessingUserCachesResult(): void
    {
        $adminUser = new User();

        $this->mockUserQueryBuilder($adminUser);

        $first = $this->service->getProcessingUser();
        $second = $this->service->getProcessingUser();

        $this->assertSame($first, $second);
    }

    public function testAttachChatSetsId(): void
    {
        $session = new GuestSession();
        $this->assertNull($session->getChatId());

        $this->service->attachChat($session, 42);

        $this->assertSame(42, $session->getChatId());
    }

    public function testAttachChatSkipsWhenAlreadyAttached(): void
    {
        $session = new GuestSession();
        $session->setChatId(42);

        $this->service->attachChat($session, 42);

        $this->assertSame(42, $session->getChatId());
    }

    private function mockUserQueryBuilder(?User $result): void
    {
        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn($result);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->userRepository->expects($this->once())
            ->method('createQueryBuilder')
            ->with('u')
            ->willReturn($qb);
    }
}

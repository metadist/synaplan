<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;
use App\Service\GuestSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class GuestSessionServiceTest extends TestCase
{
    /**
     * @param EntityManagerInterface|null $em          override EntityManager
     * @param GuestSessionRepository|null $sessionRepo override session repository
     * @param UserRepository|null         $userRepo    override user repository
     * @param LoggerInterface|null        $logger      override logger
     */
    private function createService(
        ?EntityManagerInterface $em = null,
        ?GuestSessionRepository $sessionRepo = null,
        ?UserRepository $userRepo = null,
        ?LoggerInterface $logger = null,
    ): GuestSessionService {
        return new GuestSessionService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $sessionRepo ?? $this->createStub(GuestSessionRepository::class),
            $userRepo ?? $this->createStub(UserRepository::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testCreateSessionWithCloudflareHeaders(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '203.0.113.42');
        $request->headers->set('CF-IPCountry', 'DE');

        $sessionRepo = $this->createStub(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('test-uuid-123', $request);

        $this->assertSame('test-uuid-123', $session->getSessionId());
        $this->assertSame('203.0.113.42', $session->getIpAddress());
        $this->assertSame('DE', $session->getCountry());
        $this->assertSame(5, $session->getMaxMessages());
        $this->assertSame(0, $session->getMessageCount());
    }

    public function testCreateSessionWithoutCloudflareHeaders(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $sessionRepo = $this->createStub(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('fallback-uuid', $request);

        $this->assertSame('127.0.0.1', $session->getIpAddress());
        $this->assertNull($session->getCountry());
    }

    public function testCreateSessionFiltersTorCountry(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');
        $request->headers->set('CF-IPCountry', 'T1');

        $sessionRepo = $this->createStub(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('tor-uuid', $request);

        $this->assertNull($session->getCountry());
    }

    public function testCreateSessionThrowsLogicExceptionOnDuplicateId(): void
    {
        $existing = new GuestSession();
        $existing->setSessionId('duplicate-uuid');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('findBySessionId')
            ->with('duplicate-uuid')
            ->willReturn($existing);

        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $service = $this->createService(sessionRepo: $sessionRepo);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session ID already exists');

        $service->createSession('duplicate-uuid', $request);
    }

    public function testCreateSessionThrowsOverflowWhenIpLimitExceeded(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('countActiveSessionsByIp')
            ->with('1.2.3.4')
            ->willReturn(5);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('IP rate limit exceeded'),
                $this->callback(fn (array $ctx) => '1.2.3.4' === $ctx['ip'] && 5 === $ctx['active_sessions'])
            );

        $service = $this->createService(sessionRepo: $sessionRepo, logger: $logger);

        $this->expectException(\OverflowException::class);

        $service->createSession('rate-limited-uuid', $request);
    }

    public function testCreateSessionAllowsWhenUnderIpLimit(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('countActiveSessionsByIp')
            ->with('1.2.3.4')
            ->willReturn(4);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('allowed-uuid', $request);

        $this->assertSame('allowed-uuid', $session->getSessionId());
    }

    public function testGetSessionDelegatesToRepository(): void
    {
        $expectedSession = new GuestSession();
        $expectedSession->setSessionId('find-me');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('findBySessionId')
            ->with('find-me')
            ->willReturn($expectedSession);

        $service = $this->createService(sessionRepo: $sessionRepo);
        $result = $service->getSession('find-me');

        $this->assertSame($expectedSession, $result);
    }

    public function testGetSessionReturnsNullWhenNotFound(): void
    {
        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->once())
            ->method('findBySessionId')
            ->willReturn(null);

        $service = $this->createService(sessionRepo: $sessionRepo);
        $result = $service->getSession('nonexistent');

        $this->assertNull($result);
    }

    public function testCheckLimitReturnsTrueWhenUnderLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(3);

        $service = $this->createService();

        $this->assertTrue($service->checkLimit($session));
    }

    public function testCheckLimitReturnsFalseWhenAtLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(5);

        $service = $this->createService();

        $this->assertFalse($service->checkLimit($session));
    }

    public function testCheckLimitReturnsFalseWhenOverLimit(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(7);

        $service = $this->createService();

        $this->assertFalse($service->checkLimit($session));
    }

    public function testIncrementCountFlushesEntityManager(): void
    {
        $session = new GuestSession();
        $session->setMessageCount(2);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em);
        $service->incrementCount($session);

        $this->assertSame(3, $session->getMessageCount());
    }

    public function testGetRemainingMessages(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(2);

        $service = $this->createService();

        $this->assertSame(3, $service->getRemainingMessages($session));
    }

    public function testGetProcessingUserReturnsAdminUser(): void
    {
        $adminUser = new User();
        $reflection = new \ReflectionProperty($adminUser, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($adminUser, 1);

        $userRepo = $this->createMock(UserRepository::class);
        $service = $this->createService(userRepo: $userRepo);
        $this->mockUserQueryBuilder($userRepo, $adminUser);

        $result = $service->getProcessingUser();

        $this->assertSame($adminUser, $result);
    }

    public function testGetProcessingUserReturnsNullAndLogsWhenNoAdmin(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('No admin user found'));

        $service = $this->createService(userRepo: $userRepo, logger: $logger);
        $this->mockUserQueryBuilder($userRepo, null);

        $result = $service->getProcessingUser();

        $this->assertNull($result);
    }

    public function testGetProcessingUserCachesResult(): void
    {
        $adminUser = new User();

        $userRepo = $this->createMock(UserRepository::class);
        $service = $this->createService(userRepo: $userRepo);
        $this->mockUserQueryBuilder($userRepo, $adminUser);

        $first = $service->getProcessingUser();
        $second = $service->getProcessingUser();

        $this->assertSame($first, $second);
    }

    public function testAttachChatSetsId(): void
    {
        $session = new GuestSession();
        $this->assertNull($session->getChatId());

        $service = $this->createService();
        $service->attachChat($session, 42);

        $this->assertSame(42, $session->getChatId());
    }

    public function testAttachChatSkipsWhenAlreadyAttached(): void
    {
        $session = new GuestSession();
        $session->setChatId(42);

        $service = $this->createService();
        $service->attachChat($session, 42);

        $this->assertSame(42, $session->getChatId());
    }

    private function mockUserQueryBuilder(UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepo, ?User $result): void
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

        $qb = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $userRepo->expects($this->once())
            ->method('createQueryBuilder')
            ->with('u')
            ->willReturn($qb);
    }
}

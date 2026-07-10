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
        ?int $maxSessionsPerIp = null,
    ): GuestSessionService {
        $args = [
            $em ?? $this->createStub(EntityManagerInterface::class),
            $sessionRepo ?? $this->createStub(GuestSessionRepository::class),
            $userRepo ?? $this->createStub(UserRepository::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        ];

        if (null !== $maxSessionsPerIp) {
            $args[] = $maxSessionsPerIp;
        }

        return new GuestSessionService(...$args);
    }

    public function testCreateSessionWithCloudflareHeaders(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '203.0.113.42');
        $request->headers->set('CF-IPCountry', 'DE');

        $sessionRepo = $this->createStub(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(0);
        $sessionRepo->method('sumActiveMessageCountByIp')->willReturn(0);

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
        $sessionRepo->method('sumActiveMessageCountByIp')->willReturn(0);

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
        $sessionRepo->method('sumActiveMessageCountByIp')->willReturn(0);

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
        $sessionRepo->method('sumActiveMessageCountByIp')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('allowed-uuid', $request);

        $this->assertSame('allowed-uuid', $session->getSessionId());
    }

    public function testCreateSessionCapsMaxMessagesByIpBudget(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(1);
        $sessionRepo->expects(self::any())->method('sumActiveMessageCountByIp')
            ->with('1.2.3.4')
            ->willReturn(3);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('budget-capped', $request);

        // 5 (cap) - 3 (already spent on this IP) = 2
        $this->assertSame(2, $session->getMaxMessages());
    }

    public function testCreateSessionGivesZeroMaxMessagesWhenIpBudgetExhausted(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '1.2.3.4');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->method('countActiveSessionsByIp')->willReturn(2);
        $sessionRepo->expects(self::any())->method('sumActiveMessageCountByIp')
            ->with('1.2.3.4')
            ->willReturn(5);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo);
        $session = $service->createSession('budget-exhausted', $request);

        $this->assertSame(0, $session->getMaxMessages());
        $this->assertTrue($service->isLimitReached($session));
        $this->assertFalse($service->checkLimit($session));
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

    public function testCheckLimitConsidersAggregatedIpUsage(): void
    {
        // Reproduces issue #998: a fresh-looking session (count=0) must still be
        // blocked when other active sessions on the same IP have already spent
        // the per-IP message budget (5).
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(0);
        $session->setIpAddress('1.2.3.4');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects(self::any())->method('sumActiveMessageCountByIp')
            ->with('1.2.3.4')
            ->willReturn(5);

        $service = $this->createService(sessionRepo: $sessionRepo);

        $this->assertFalse($service->checkLimit($session));
        $this->assertTrue($service->isLimitReached($session));
        $this->assertSame(0, $service->getRemainingMessages($session));
    }

    public function testGetRemainingMessagesUsesMinOfSessionAndIpBudget(): void
    {
        // Session itself has 4 left, but IP budget only 1 -> remaining = 1.
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(1);
        $session->setIpAddress('10.0.0.1');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects(self::any())->method('sumActiveMessageCountByIp')
            ->with('10.0.0.1')
            ->willReturn(3);

        $service = $this->createService(sessionRepo: $sessionRepo);

        // 5 - (3 + 1) = 1; min(4, 1) = 1
        $this->assertSame(1, $service->getRemainingMessages($session));
    }

    public function testGetRemainingMessagesIgnoresIpBudgetWhenIpMissing(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);
        $session->setMessageCount(2);

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects($this->never())->method('sumActiveMessageCountByIp');

        $service = $this->createService(sessionRepo: $sessionRepo);

        $this->assertSame(3, $service->getRemainingMessages($session));
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

        $sessionRepo = $this->createStub(GuestSessionRepository::class);
        $sessionRepo->method('sumActiveMessageCountByIp')->willReturn(0);

        $service = $this->createService(sessionRepo: $sessionRepo);

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

    public function testCustomMaxSessionsPerIpIsRespected(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '10.0.0.1');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects(self::any())->method('countActiveSessionsByIp')
            ->with('10.0.0.1')
            ->willReturn(2);

        $service = $this->createService(sessionRepo: $sessionRepo, maxSessionsPerIp: 2);

        $this->expectException(\OverflowException::class);

        $service->createSession('cap-at-two', $request);
    }

    public function testHighMaxSessionsPerIpAllowsMoreSessions(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '10.0.0.1');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects(self::any())->method('countActiveSessionsByIp')
            ->with('10.0.0.1')
            ->willReturn(50);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = $this->createService(em: $em, sessionRepo: $sessionRepo, maxSessionsPerIp: 100);
        $session = $service->createSession('high-cap-uuid', $request);

        $this->assertSame('high-cap-uuid', $session->getSessionId());
    }

    public function testDefaultMaxSessionsPerIpMatchesConstant(): void
    {
        $request = new Request();
        $request->headers->set('CF-Connecting-IP', '10.0.0.1');

        $sessionRepo = $this->createMock(GuestSessionRepository::class);
        $sessionRepo->expects(self::any())->method('countActiveSessionsByIp')
            ->with('10.0.0.1')
            ->willReturn(GuestSessionService::MAX_SESSIONS_PER_IP);

        $service = $this->createService(sessionRepo: $sessionRepo);

        $this->expectException(\OverflowException::class);

        $service->createSession('default-cap', $request);
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

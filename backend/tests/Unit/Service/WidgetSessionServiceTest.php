<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\Entity\WidgetSession;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests guarding the BMESSAGECOUNT data-quality fix:
 *
 * - getOrCreateSession() must not reset the cached counter on expiry resume.
 * - checkSessionLimit() must enforce the visitor quota live from BMESSAGES,
 *   independent of the cached BMESSAGECOUNT.
 * - incrementMessageCount() must advance the counter and refresh lastMessage.
 *
 * If any of these regress, exports / dashboards immediately start lying about
 * conversation lengths again, which is the symptom these tests exist to prevent.
 */
class WidgetSessionServiceTest extends TestCase
{
    /**
     * @param array{
     *   em?: EntityManagerInterface,
     *   sessionRepository?: WidgetSessionRepository,
     *   messageRepository?: MessageRepository,
     * } $overrides
     */
    private function createService(array $overrides = []): WidgetSessionService
    {
        return new WidgetSessionService(
            $overrides['em'] ?? $this->createStub(EntityManagerInterface::class),
            $overrides['sessionRepository'] ?? $this->createStub(WidgetSessionRepository::class),
            $overrides['messageRepository'] ?? $this->createStub(MessageRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(AiFacade::class),
            $this->createStub(ModelConfigService::class),
            $this->createStub(RateLimitService::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testCheckSessionLimitAllowsFreshSessionWithoutChat(): void
    {
        $session = new WidgetSession();
        $session->setWidgetId('widget-1');
        $session->setSessionId('session-1');
        // No chatId attached → no messages possible → quota wide open. The repository
        // must NOT be queried in this case (early return saves a DB roundtrip on the
        // hot path of every first-message request).

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->expects($this->never())->method('countByChatId');

        $service = $this->createService(['messageRepository' => $messageRepository]);
        $result = $service->checkSessionLimit($session, 50, 0);

        $this->assertTrue($result['allowed']);
        $this->assertSame(50, $result['remaining']);
        $this->assertNull($result['reason']);
    }

    public function testCheckSessionLimitBlocksWhenLiveVisitorCountReachesLimit(): void
    {
        $session = new WidgetSession();
        $session->setWidgetId('widget-1');
        $session->setSessionId('session-1');
        $session->setChatId(42);

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->expects($this->once())
            ->method('countByChatId')
            ->with(42, 'IN', $this->isInt(), true)
            ->willReturn(50);

        $service = $this->createService(['messageRepository' => $messageRepository]);
        $result = $service->checkSessionLimit($session, 50, 0);

        $this->assertFalse($result['allowed']);
        $this->assertSame('total_limit_reached', $result['reason']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testCheckSessionLimitIgnoresCachedCounterAndRespectsLiveCount(): void
    {
        $session = new WidgetSession();
        $session->setWidgetId('widget-1');
        $session->setSessionId('session-1');
        $session->setChatId(42);
        // Cached counter inflated by AI/operator/system replies — must NOT consume quota.
        $session->setMessageCount(120);

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->expects($this->once())
            ->method('countByChatId')
            ->with(42, 'IN', $this->isInt(), true)
            ->willReturn(3);

        $service = $this->createService(['messageRepository' => $messageRepository]);
        $result = $service->checkSessionLimit($session, 50, 0);

        $this->assertTrue($result['allowed'], 'Cached BMESSAGECOUNT must not gate the quota');
        $this->assertSame(47, $result['remaining']);
    }

    public function testCheckSessionLimitWindowsCountToSessionExpiryHours(): void
    {
        $session = new WidgetSession();
        $session->setWidgetId('widget-1');
        $session->setSessionId('session-1');
        $session->setChatId(99);

        $now = time();
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->expects($this->once())
            ->method('countByChatId')
            ->with(
                99,
                'IN',
                $this->callback(function (int $since) use ($now): bool {
                    $expectedWindow = WidgetSessionService::SESSION_EXPIRY_HOURS * 3600;

                    // Allow ±5s drift for test execution time.
                    return abs(($now - $since) - $expectedWindow) <= 5;
                }),
                true,
            )
            ->willReturn(0);

        $service = $this->createService(['messageRepository' => $messageRepository]);
        $service->checkSessionLimit($session, 50, 0);
    }

    public function testGetOrCreateSessionDoesNotResetCounterOnExpiryResume(): void
    {
        $existingSession = new WidgetSession();
        $existingSession->setWidgetId('widget-1');
        $existingSession->setSessionId('returning-visitor');
        $existingSession->setChatId(7);
        $existingSession->setMessageCount(42); // historical conversation length
        $existingSession->setFileCount(3);
        $existingSession->setExpires(time() - 3600); // expired one hour ago

        $sessionRepository = $this->createStub(WidgetSessionRepository::class);
        $sessionRepository->method('findByWidgetAndSession')->willReturn($existingSession);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $em->expects($this->never())->method('persist');

        $service = $this->createService([
            'em' => $em,
            'sessionRepository' => $sessionRepository,
        ]);

        $result = $service->getOrCreateSession('widget-1', 'returning-visitor');

        $this->assertSame(42, $result->getMessageCount(), 'Counter must survive expiry resume');
        $this->assertSame(3, $result->getFileCount(), 'File count must survive expiry resume');
        $this->assertGreaterThan(time(), $result->getExpires(), 'Expiry must be extended');
    }

    public function testIncrementMessageCountAdvancesCounterAndRefreshesLastMessage(): void
    {
        $session = new WidgetSession();
        $session->setWidgetId('widget-1');
        $session->setSessionId('session-1');
        $session->setMessageCount(5);
        $previousLastMessage = $session->getLastMessage();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->createService(['em' => $em]);
        $service->incrementMessageCount($session);

        $this->assertSame(6, $session->getMessageCount());
        $this->assertGreaterThanOrEqual($previousLastMessage, $session->getLastMessage());
    }
}

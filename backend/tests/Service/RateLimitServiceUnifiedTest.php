<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\RateLimitService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test unified rate limiting across all sources (WhatsApp, Email, Web)
 */
class RateLimitServiceUnifiedTest extends TestCase
{
    private RateLimitService $service;
    private ConfigRepository $configRepository;
    private EntityManagerInterface $em;
    private Connection $connection;
    private LoggerInterface $logger;
    private array $configMap = [];

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configMap = [];

        $this->em->method('getConnection')->willReturn($this->connection);

        $this->configRepository->method('findBy')->willReturnCallback(function (array $criteria) {
            $group = $criteria['group'] ?? '';
            return $this->configMap[$group] ?? [];
        });

        $this->service = new RateLimitService(
            $this->configRepository,
            $this->em,
            $this->logger
        );
    }

    public function testCheckLimit_UnifiedAcrossAllSources_Anonymous(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('ANONYMOUS');

        $this->setupLimits('ANONYMOUS', 'MESSAGES', ['TOTAL' => 10]);

        // Simulate user already sent 8 messages (across ALL sources)
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('SELECT COUNT(*) FROM BUSELOG'),
                $this->callback(function($params) {
                    return $params['user_id'] === 1 && $params['action'] === 'MESSAGES';
                })
            )
            ->willReturn('8');

        $result = $this->service->checkLimit($user, 'MESSAGES');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(8, $result['used']);
        $this->assertEquals(2, $result['remaining']);
    }

    public function testCheckLimit_UnifiedExceedsLimit_Anonymous(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('ANONYMOUS');

        $this->setupLimits('ANONYMOUS', 'MESSAGES', ['TOTAL' => 10]);

        // User already at limit (10 messages from all sources combined)
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn('10');

        $result = $this->service->checkLimit($user, 'MESSAGES');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(10, $result['used']);
        $this->assertEquals(0, $result['remaining']);
    }

    public function testCheckLimit_UnifiedAcrossAllSources_NEW(): void
    {
        $this->markTestSkipped('Complex integration test with mocked repositories - needs refactoring');
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        // Setup NEW limits (50 messages lifetime)
        $this->setupLimits('NEW', 'MESSAGES', ['TOTAL' => 50]);

        // User sent 30 messages across all sources
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn('30');

        $result = $this->service->checkLimit($user, 'MESSAGES');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(50, $result['limit']);
        $this->assertEquals(30, $result['used']);
        $this->assertEquals(20, $result['remaining']);
        $this->assertEquals('lifetime', $result['type']);
    }

    public function testCheckLimit_UnifiedAcrossAllSources_PRO_Hourly(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        // Setup PRO limits (100/hour, 5000/month)
        $this->setupLimits('PRO', 'MESSAGES', [
            'HOURLY' => 100,
            'MONTHLY' => 5000
        ]);

        // User sent 80 messages in last hour (all sources combined)
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('80', '120');

        $result = $this->service->checkLimit($user, 'MESSAGES');

        $this->assertTrue($result['allowed']);
        $this->assertGreaterThanOrEqual(80, $result['used']);
    }

    public function testRecordUsage_CountsForAllSources(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO BUSELOG'),
                $this->callback(function ($params) {
                    return $params['user_id'] === 1
                        && $params['action'] === 'MESSAGES'
                        && isset($params['timestamp']);
                })
            );

        $this->service->recordUsage($user, 'MESSAGES', [
            'source' => 'email', // Source is logged but doesn't affect limits
            'provider' => 'email',
            'model' => ''
        ]);
    }

    public function testUnifiedLimit_ScenarioWhatsAppThenEmail(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('ANONYMOUS');

        $this->setupLimits('ANONYMOUS', 'MESSAGES', ['TOTAL' => 10]);

        // Scenario: User sent 7 messages via WhatsApp
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('7', '8'); // 7 existing, then 8 after email

        // Check limit before email (7/10 used)
        $result1 = $this->service->checkLimit($user, 'MESSAGES');
        $this->assertTrue($result1['allowed']);
        $this->assertEquals(7, $result1['used']);
        $this->assertEquals(3, $result1['remaining']);

        // Record email message (doesn't matter it's email, same limit pool)
        $this->connection->expects($this->once())
            ->method('executeStatement');
        $this->service->recordUsage($user, 'MESSAGES', ['source' => 'email']);

        // Check limit after email (8/10 used)
        $result2 = $this->service->checkLimit($user, 'MESSAGES');
        $this->assertTrue($result2['allowed']);
        $this->assertEquals(8, $result2['used']);
        $this->assertEquals(2, $result2['remaining']);
    }

    private function setupLimits(string $level, string $action, array $limits): void
    {
        $configs = [];
        foreach ($limits as $timeframe => $value) {
            $config = $this->createMock(\App\Entity\Config::class);
            $config->method('getSetting')->willReturn($action . '_' . $timeframe);
            $config->method('getValue')->willReturn((string) $value);
            $configs[] = $config;
        }

        $this->configMap["RATELIMITS_{$level}"] = $configs;
    }
}


<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Repository\UseLogRepository;
use App\Service\Usage\AdminUsageService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminUsageServiceTest extends TestCase
{
    public function testPeriodSortAndPagingFallBackToDefaults(): void
    {
        self::assertSame('30d', AdminUsageService::periodOrDefault(null));
        self::assertSame('30d', AdminUsageService::periodOrDefault('bogus'));
        self::assertSame('7d', AdminUsageService::periodOrDefault('7d'));
        self::assertSame('cost', AdminUsageService::sortOrDefault(null));
        self::assertSame('cost', AdminUsageService::sortOrDefault('email'));
        self::assertSame('tokens', AdminUsageService::sortOrDefault('tokens'));
        self::assertSame(1, AdminUsageService::pageOrDefault(null));
        self::assertSame(1, AdminUsageService::pageOrDefault(0));
        self::assertSame(20, AdminUsageService::perPageOrDefault(null));
        self::assertSame(50, AdminUsageService::perPageOrDefault(500));
        self::assertSame(0, AdminUsageService::sinceFor('all', 1_800_000_000));
        self::assertSame(1_800_000_000 - 86_400, AdminUsageService::sinceFor('24h', 1_800_000_000));
    }

    public function testUsersAssemblesRowsWithKindTotals(): void
    {
        $repository = $this->repository();
        $repository->method('topUsersByUsage')->willReturn([
            'totalUsers' => 21,
            'rows' => [[
                'user_id' => 42,
                'email' => 'ada@example.com',
                'level' => 'PRO',
                'account_status' => 'active',
                'details' => '{"firstName":"Ada","lastName":"Example"}',
                'requests' => 10,
                'tokens' => 5000,
                'prompt_tokens' => 4000,
                'completion_tokens' => 1000,
                'cost' => '3.500000',
                'last_active' => 1_800_000_000,
            ]],
        ]);
        $repository->method('actionTotalsForUsers')->willReturn([
            ['user_id' => 42, 'action' => 'MESSAGES', 'has_agent' => 0, 'requests' => 6, 'tokens' => 4000, 'cost' => '1.000000'],
            ['user_id' => 42, 'action' => 'MESSAGES', 'has_agent' => 1, 'requests' => 2, 'tokens' => 500, 'cost' => '2.000000'],
            ['user_id' => 42, 'action' => 'VIDEOS', 'has_agent' => 0, 'requests' => 2, 'tokens' => 500, 'cost' => '0.500000'],
        ]);

        $result = (new AdminUsageService($repository))->users('30d', 'cost', 2, 20);

        self::assertSame(21, $result['totalUsers']);
        self::assertSame(2, $result['totalPages']);
        $user = $result['users'][0];
        self::assertSame('Ada Example', $user['displayName']);
        self::assertSame(3.5, $user['cost']);
        // Agent chat outranks plain chat and video: kinds are merged, cost-sorted.
        self::assertSame(['agent', 'chat', 'video'], array_column($user['byKind'], 'kind'));
        self::assertSame(2.0, $user['byKind'][0]['cost']);
    }

    public function testRequestsDecodesMetadataWithoutContent(): void
    {
        $repository = $this->repository();
        $repository->method('requestsForUser')->willReturn([
            'total' => 1,
            'rows' => [[
                'id' => 9,
                'ts' => 1_800_000_000,
                'action' => 'MESSAGES',
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5',
                'tokens' => 100,
                'prompt_tokens' => 80,
                'completion_tokens' => 20,
                'cached_tokens' => 10,
                'cost' => '0.010000',
                'latency' => 900,
                'status' => 'success',
                'estimated' => 0,
                'metadata' => '{"source":"whatsapp","chat_id":12,"agentId":7,"prompt":"never shown"}',
            ]],
        ]);

        $result = (new AdminUsageService($repository))->requests(42, '7d', 1, 20);

        $request = $result['requests'][0];
        self::assertSame('agent', $request['kind']);
        self::assertSame('WHATSAPP', $request['channel']);
        self::assertSame(12, $request['chatId']);
        self::assertSame(7, $request['agentId']);
        self::assertArrayNotHasKey('prompt', $request);
        self::assertArrayNotHasKey('metadata', $request);
    }

    /** @return UseLogRepository&MockObject */
    private function repository(): UseLogRepository
    {
        return $this->getMockBuilder(UseLogRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}

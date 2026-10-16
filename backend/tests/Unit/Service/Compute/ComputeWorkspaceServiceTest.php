<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\ComputeWorkspace;
use App\Entity\User;
use App\Repository\ComputeWorkspaceRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeWorkspaceService;
use App\Service\Compute\Contract\ComputeWorkspaceCreate;
use App\Service\Compute\Contract\ComputeWorkspaceCreated;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ComputeWorkspaceServiceTest extends TestCase
{
    public function testOwnerStringMatchesUser(): void
    {
        self::assertSame('user:42', ComputeWorkspaceService::ownerString(42));
    }

    public function testNoPathEverSent(): void
    {
        $created = new ComputeWorkspaceCreated('01ARZ3NDEKTSV4RRFFQ69G5FAV', 256);
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('createWorkspace')
            ->with(self::callback(static function (ComputeWorkspaceCreate $body): bool {
                $payload = ['owner' => $body->owner, 'quotaMb' => $body->quotaMb];
                self::assertSame(['owner', 'quotaMb'], array_keys($payload));
                self::assertSame('user:7', $body->owner);
                self::assertSame(256, $body->quotaMb);
                self::assertStringNotContainsString('/', json_encode($payload, \JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString('\\', json_encode($payload, \JSON_THROW_ON_ERROR));

                return true;
            }))
            ->willReturn($created);

        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn(null);
        $repo->expects($this->once())->method('save');
        $client->expects($this->never())->method('deleteWorkspace');

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturn(256);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits, $this->locks());
        $row = $service->ensure($user);

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $row->getWorkspaceId());
    }

    public function testEnsureReusesRowSavedByRacingCallerInsteadOfCreatingASecondFolder(): void
    {
        // First lookup misses (pre-lock), second one (under the lock) sees the
        // row a concurrent caller just saved — no sidecar create may happen.
        $existing = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256, null);
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->expects($this->exactly(2))->method('findAccessibleForUser')->willReturnOnConsecutiveCalls(null, $existing);
        $repo->expects($this->never())->method('save');

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('createWorkspace');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertSame($existing, $service->ensure($user));
    }

    public function testEnsureReleasesTheLockWhenTheSidecarFails(): void
    {
        $repo = $this->createStub(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn(null);
        $client = $this->createStub(ComputeClient::class);
        $client->method('createWorkspace')->willThrowException(new \RuntimeException('sidecar down'));
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturn(256);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');
        $locks = $this->locks();

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits, $locks);
        try {
            $service->ensure($user);
            self::fail('expected the sidecar failure to surface');
        } catch (\RuntimeException) {
        }

        self::assertTrue($locks->createLock('compute-workspace-create.7')->acquire(), 'lock must not stay held after a failed create');
    }

    public function testEnsureDeletesTheSidecarFolderWhenTheRowCannotBeSaved(): void
    {
        $created = new ComputeWorkspaceCreated('01ARZ3NDEKTSV4RRFFQ69G5FAV', 256);
        $client = $this->createMock(ComputeClient::class);
        $client->method('createWorkspace')->willReturn($created);
        $client->expects($this->once())->method('deleteWorkspace')->with('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn(null);
        $repo->method('save')->willThrowException(new \RuntimeException('duplicate key'));

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturn(256);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits, $this->locks());
        try {
            $service->ensure($user);
            self::fail('expected the save failure to surface');
        } catch (\RuntimeException $e) {
            self::assertSame('duplicate key', $e->getMessage());
        }
    }

    public function testExpiredWorkspaceIsDroppedSoTheNextEnsureCreatesANewFolder(): void
    {
        $expired = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256, new \DateTimeImmutable('-1 day'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturnOnConsecutiveCalls($expired, null);
        $repo->expects($this->once())->method('remove')->with($expired);
        $repo->expects($this->once())->method('save');

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())->method('deleteWorkspace')->with('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $client->method('createWorkspace')->willReturn(new ComputeWorkspaceCreated('01BX5ZZKBKACTAV9WEVGEMMVRY', 256));

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturn(256);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits, $this->locks());
        $row = $service->ensure($user);

        self::assertSame('01BX5ZZKBKACTAV9WEVGEMMVRY', $row->getWorkspaceId());
    }

    public function testExpiredWorkspaceStaysWhileARunHoldsIt(): void
    {
        $expired = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256, new \DateTimeImmutable('-1 day'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn($expired);
        $repo->expects($this->never())->method('remove');

        $client = $this->createMock(ComputeClient::class);
        $client->method('deleteWorkspace')->willThrowException(
            new \App\Service\Compute\ComputeRefusedException('workspace_busy', 'busy', httpStatus: 409),
        );
        $client->expects($this->never())->method('createWorkspace');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertSame($expired, $service->forUser($user));
    }

    public function testExpiredWorkspaceStaysWhenTheSidecarIsUnreachable(): void
    {
        $expired = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256, new \DateTimeImmutable('-1 day'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn($expired);
        $repo->expects($this->never())->method('remove');

        $client = $this->createMock(ComputeClient::class);
        $client->method('deleteWorkspace')->willThrowException(new \RuntimeException('sidecar down'));

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertSame($expired, $service->forUser($user));
    }

    public function testExpiringWorkspaceRenewsWhenTheOwnerOpensItInGrace(): void
    {
        $expiring = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256);
        $expiring->markExpiring(new \DateTimeImmutable('+3 days'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn($expiring);
        $repo->expects($this->once())->method('save')->with($expiring);
        $repo->expects($this->never())->method('remove');

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('deleteWorkspace');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertSame($expiring, $service->forUser($user));
        self::assertSame(ComputeWorkspace::STATUS_ACTIVE, $expiring->getStatus());
        self::assertFalse($expiring->isExpired());
    }

    public function testExpiringWorkspacePastGraceIsDropped(): void
    {
        $due = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256);
        $due->markExpiring(new \DateTimeImmutable('-1 day'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn($due);
        $repo->expects($this->once())->method('remove')->with($due);

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())->method('deleteWorkspace')->with('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertNull($service->forUser($user));
    }

    public function testEnsureReusesExpiringRowInsteadOfCreatingASecondFolder(): void
    {
        $expiring = new ComputeWorkspace(7, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 256);
        $expiring->markExpiring(new \DateTimeImmutable('+3 days'));
        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn($expiring);

        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->never())->method('createWorkspace');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $this->createStub(RateLimitService::class), $this->locks());

        self::assertSame($expiring, $service->ensure($user));
        self::assertSame(ComputeWorkspace::STATUS_ACTIVE, $expiring->getStatus());
    }

    private function locks(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }

    public function testSafeRelativePathNormalisesAcceptedInput(): void
    {
        self::assertSame('out/report.csv', ComputeWorkspaceService::safeRelativePath('  /out\\report.csv ', true));
        self::assertSame('report..csv', ComputeWorkspaceService::safeRelativePath('report..csv', true));
        self::assertSame('', ComputeWorkspaceService::safeRelativePath(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedPaths(): iterable
    {
        yield 'traversal' => ['../etc/passwd'];
        yield 'traversal inside' => ['out/../../etc/passwd'];
        yield 'backslash traversal' => ['..\\..\\secret'];
        yield 'nul byte' => ["out/report.csv\0.png"];
        yield 'newline' => ["out/\nreport.csv"];
        yield 'too long' => [str_repeat('a', 1025)];
        yield 'empty when a file is required' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPaths')]
    public function testSafeRelativePathRejects(string $path): void
    {
        $this->expectException(\App\Service\Compute\ComputeRefusedException::class);
        ComputeWorkspaceService::safeRelativePath($path, true);
    }

    private function config(): ComputeConfig
    {
        $repo = $this->createStub(\App\Repository\ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }

    /**
     * Issue #1875 / PR #1949: when COMPUTE_WORKSPACE_MB is missing, the
     * fallback must follow the resolved group tier, not the billing level.
     */
    public function testMissingWorkspaceQuotaRowUsesGroupTierFallback(): void
    {
        $created = new ComputeWorkspaceCreated('01ARZ3NDEKTSV4RRFFQ69G5FAV', 2048);
        $client = $this->createMock(ComputeClient::class);
        $client->expects($this->once())
            ->method('createWorkspace')
            ->with(self::callback(static function (ComputeWorkspaceCreate $body): bool {
                self::assertSame(2048, $body->quotaMb);

                return true;
            }))
            ->willReturn($created);

        $repo = $this->createMock(ComputeWorkspaceRepository::class);
        $repo->method('findAccessibleForUser')->willReturn(null);
        $repo->expects($this->once())->method('save');

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturnCallback(
            static fn (User $_user, string $_setting, int $fallback): int => $fallback,
        );
        $limits->method('resolveRateLimitLevel')->willReturn('BUSINESS');

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits, $this->locks());
        $service->ensure($user);
    }
}

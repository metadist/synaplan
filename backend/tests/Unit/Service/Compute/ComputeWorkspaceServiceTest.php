<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\User;
use App\Repository\ComputeWorkspaceRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeWorkspaceService;
use App\Service\Compute\Contract\ComputeWorkspaceCreate;
use App\Service\Compute\Contract\ComputeWorkspaceCreated;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;

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
        $repo->method('findActiveForUser')->willReturn(null);
        $repo->expects($this->once())->method('save');

        $limits = $this->createStub(RateLimitService::class);
        $limits->method('computeIntSetting')->willReturn(256);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('NEW');

        $service = new ComputeWorkspaceService($this->config(), $client, $repo, $limits);
        $row = $service->ensure($user);

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $row->getWorkspaceId());
    }

    public function testSafeRelativePathRejectsTraversal(): void
    {
        $this->expectException(\App\Service\Compute\ComputeRefusedException::class);
        ComputeWorkspaceService::safeRelativePath('../etc/passwd', true);
    }

    private function config(): ComputeConfig
    {
        $repo = $this->createStub(\App\Repository\ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }
}

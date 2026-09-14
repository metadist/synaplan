<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeEgressResolver;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class ComputeEgressResolverTest extends TestCase
{
    public function testEgressDisabledYieldsEmptyAllow(): void
    {
        $resolved = $this->resolver(egressOn: false)->resolve(['example.com']);

        self::assertSame(['allow' => []], $resolved);
    }

    public function testBlockedHostRefused(): void
    {
        $this->expectException(ComputeRefusedException::class);
        $this->expectExceptionMessage('cannot reach that website');
        $this->resolver()->resolve(['localhost']);
    }

    public function testPrivateResolutionRefused(): void
    {
        $this->expectException(ComputeRefusedException::class);
        $this->resolver()->resolve(['10.0.0.8']);
    }

    public function testPinnedIpsEmitted(): void
    {
        $resolved = $this->resolver()->resolve(['8.8.8.8']);

        self::assertSame([
            'allow' => [
                ['host' => '8.8.8.8', 'port' => 443, 'ips' => ['8.8.8.8']],
            ],
        ], $resolved);
    }

    public function testMaxHostsCap(): void
    {
        $this->expectException(ComputeRefusedException::class);
        $this->expectExceptionMessage('too many websites');
        $this->resolver()->resolve(['a.example', 'b.example', 'c.example']);
    }

    private function resolver(bool $egressOn = true): ComputeEgressResolver
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $_owner, string $group, string $setting) use ($egressOn): ?string {
                if ('COMPUTE' !== $group) {
                    return null;
                }

                return match ($setting) {
                    'ENABLED' => '1',
                    'EGRESS_ENABLED' => $egressOn ? '1' : '0',
                    'EGRESS_MAX_HOSTS' => '2',
                    default => null,
                };
            },
        );
        $config = new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');

        return new ComputeEgressResolver($config, new SsrfGuard());
    }
}

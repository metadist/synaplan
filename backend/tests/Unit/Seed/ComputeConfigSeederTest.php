<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\ComputeConfigSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ComputeConfigSeederTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $this->envBackup = [];
    }

    public function testEnabledOnlyWithUrl(): void
    {
        $this->setEnv('COMPUTE_URL', 'http://compute:8080');
        $this->setEnv('COMPUTE_TOKEN', 'token-token-token-token-token-32b');
        self::assertSame('1', ComputeConfigSeeder::enabledSeedValue());

        $this->setEnv('COMPUTE_TOKEN', '');
        self::assertSame('0', ComputeConfigSeeder::enabledSeedValue());

        $this->setEnv('COMPUTE_URL', '');
        self::assertSame('0', ComputeConfigSeeder::enabledSeedValue());
    }

    public function testSeedWritesResolvedValue(): void
    {
        $this->setEnv('COMPUTE_URL', 'http://compute:8080');
        $this->setEnv('COMPUTE_TOKEN', 'token-token-token-token-token-32b');

        $seen = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$seen): int {
                $seen[] = $params;

                return 1;
            }
        );

        $result = (new ComputeConfigSeeder($connection))->seed();

        $enabled = null;
        $workspaces = null;
        $tier = null;
        foreach ($seen as $params) {
            if ('ENABLED' === ($params[2] ?? null)) {
                $enabled = $params[3] ?? null;
            }
            if ('WORKSPACES_ENABLED' === ($params[2] ?? null)) {
                $workspaces = $params[3] ?? null;
            }
            if ('REQUIRE_TIER' === ($params[2] ?? null)) {
                $tier = $params[3] ?? null;
            }
        }
        self::assertSame('1', $enabled);
        self::assertSame('1', $workspaces);
        self::assertSame('docker', $tier);
        self::assertGreaterThan(0, $result->inserted);
    }

    public function testNeverOverwrites(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $result = (new ComputeConfigSeeder($connection))->seed();

        self::assertSame(0, $result->inserted);
        self::assertGreaterThan(0, $result->skipped);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!\array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = $_ENV[$key] ?? false;
        }
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }
}

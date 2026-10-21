<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\TestProviderChunkDelay;
use PHPUnit\Framework\TestCase;

final class TestProviderChunkDelayTest extends TestCase
{
    private mixed $server;

    private mixed $env;

    private string|false $process;

    protected function setUp(): void
    {
        $this->server = $_SERVER['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] ?? null;
        $this->env = $_ENV['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] ?? null;
        $this->process = getenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS');
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
        if (null !== $this->server) {
            $_SERVER['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] = $this->server;
        }
        if (null !== $this->env) {
            $_ENV['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] = $this->env;
        }
        if (false === $this->process) {
            putenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS');
        } else {
            putenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS='.$this->process);
        }
    }

    public function testUnsetStaysAtFiftyMilliseconds(): void
    {
        self::assertSame(50_000, TestProviderChunkDelay::microseconds());
    }

    public function testFourHundredMilliseconds(): void
    {
        $this->setDelay('400');

        self::assertSame(400_000, TestProviderChunkDelay::microseconds());
    }

    public function testInvalidValuesStayAtTheDefault(): void
    {
        foreach (['nope', '', '-1'] as $raw) {
            $this->setDelay($raw);
            self::assertSame(50_000, TestProviderChunkDelay::microseconds(), $raw);
        }
    }

    private function clear(): void
    {
        unset($_SERVER['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'], $_ENV['SYNAPLAN_TEST_PROVIDER_CHUNK_MS']);
        putenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS');
    }

    private function setDelay(string $raw): void
    {
        $_SERVER['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] = $raw;
        $_ENV['SYNAPLAN_TEST_PROVIDER_CHUNK_MS'] = $raw;
        putenv('SYNAPLAN_TEST_PROVIDER_CHUNK_MS='.$raw);
    }
}

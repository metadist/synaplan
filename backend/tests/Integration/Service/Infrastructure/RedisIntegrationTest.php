<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Infrastructure;

use App\Service\Infrastructure\RedisService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests that verify Redis connectivity and basic operations
 * against a live Redis instance.
 *
 * These tests exist because every other Redis consumer in the test env is
 * overridden (cache→filesystem, sessions→mock_file, messenger→in-memory,
 * health→skipped). Without at least one test hitting a real Redis, a
 * misconfigured DSN, incompatible Redis version, or broken ext-redis can
 * ship to production undetected.
 *
 * Anti-flakiness measures:
 *   - Every key uses a per-run UUID prefix → no collision across parallel runs
 *   - All keys are created with a short TTL → self-clean even on crash
 *   - tearDown deletes every key explicitly → belt and suspenders
 *   - Tests that need Redis skip gracefully when it's unreachable (local dev)
 */
final class RedisIntegrationTest extends KernelTestCase
{
    private RedisService $redis;

    /** @var list<string> Keys created during the test, cleaned up in tearDown. */
    private array $keysToCleanup = [];

    private string $runPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->redis = static::getContainer()->get(RedisService::class);
        $this->runPrefix = 'ci_smoke_'.bin2hex(random_bytes(4)).':';

        if (!$this->redis->isAvailable()) {
            $this->markTestSkipped(
                'Redis is not reachable — skipping integration test. '
                .'This is expected in local dev without a running Redis.'
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->keysToCleanup as $key) {
            $this->redis->delete($key);
        }

        parent::tearDown();
    }

    public function testPingSucceeds(): void
    {
        self::assertTrue($this->redis->ping(), 'Redis PING should return true on a live instance');
    }

    public function testIsAvailableReturnsTrue(): void
    {
        self::assertTrue($this->redis->isAvailable());
    }

    public function testServerVersionIsReported(): void
    {
        $version = $this->redis->serverVersion();

        self::assertNotNull($version, 'serverVersion() should return a version string');
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+/',
            $version,
            'Version should look like "7.4.x" or similar'
        );
    }

    public function testSetAndGet(): void
    {
        $key = $this->trackKey('setget');

        self::assertTrue($this->redis->set($key, 'hello', 30));
        self::assertSame('hello', $this->redis->get($key));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $key = $this->runPrefix.'nonexistent_'.bin2hex(random_bytes(4));

        self::assertNull($this->redis->get($key));
    }

    public function testDelete(): void
    {
        $key = $this->trackKey('delete');

        $this->redis->set($key, 'to_delete', 30);
        self::assertTrue($this->redis->exists($key), 'Key should exist before delete');

        self::assertTrue($this->redis->delete($key));
        self::assertFalse($this->redis->exists($key), 'Key should not exist after delete');
    }

    public function testExists(): void
    {
        $key = $this->trackKey('exists');

        self::assertFalse($this->redis->exists($key));

        $this->redis->set($key, 'present', 30);
        self::assertTrue($this->redis->exists($key));
    }

    public function testIncrement(): void
    {
        $key = $this->trackKey('incr');

        self::assertSame(1, $this->redis->increment($key));
        self::assertSame(2, $this->redis->increment($key));
        self::assertSame(7, $this->redis->increment($key, 5));

        $this->redis->expire($key, 30);
    }

    public function testSetWithTtl(): void
    {
        $key = $this->trackKey('ttl');

        self::assertTrue($this->redis->set($key, 'expires_soon', 5));
        self::assertSame('expires_soon', $this->redis->get($key));
    }

    public function testExpire(): void
    {
        $key = $this->trackKey('expire');

        $this->redis->set($key, 'will_expire', 300);
        self::assertTrue($this->redis->expire($key, 10));
    }

    public function testOverwriteExistingKey(): void
    {
        $key = $this->trackKey('overwrite');

        $this->redis->set($key, 'first', 30);
        self::assertSame('first', $this->redis->get($key));

        $this->redis->set($key, 'second', 30);
        self::assertSame('second', $this->redis->get($key));
    }

    public function testPublishReturnsSubscriberCount(): void
    {
        $result = $this->redis->publish('test_channel', 'test_payload');

        self::assertIsInt($result);
        self::assertSame(0, $result);
    }

    public function testKeyPrefixIsolatesEnvironment(): void
    {
        $key = $this->trackKey('prefix_check');

        $this->redis->set($key, 'prefixed', 30);

        $rawClient = new \Predis\Client(
            $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? 'redis://127.0.0.1:6379',
            ['parameters' => ['read_write_timeout' => 2.5, 'timeout' => 2.5]],
        );

        $prefixedKey = 'synaplan:test:'.$key;
        $rawValue = $rawClient->get($prefixedKey);

        self::assertSame('prefixed', $rawValue, 'Key must be stored with synaplan:{env}: prefix');

        $rawClient->del([$prefixedKey]);
    }

    /**
     * Register a key for automatic cleanup and return its full name.
     */
    private function trackKey(string $suffix): string
    {
        $key = $this->runPrefix.$suffix;
        $this->keysToCleanup[] = $key;

        return $key;
    }
}

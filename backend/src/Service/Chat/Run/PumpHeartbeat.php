<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Service\Infrastructure\RedisService;
use Predis\Client;

/**
 * Renews one pump's heartbeat while a turn blocks inside the provider.
 *
 * The child opens its own Redis connection. On stop it is signalled and
 * kills itself without running destructors, so it cannot close the
 * parent's sockets.
 */
final class PumpHeartbeat
{
    public function __construct(
        private readonly RedisService $redis,
    ) {
    }

    public function start(string $incarnation, string $serverId): ?int
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            return null;
        }
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? getenv('REDIS_DSN');
        if (!is_string($dsn) || '' === $dsn) {
            return null;
        }
        $incarnationKey = $this->redis->absoluteKey('pump:'.$incarnation);
        $aliveKey = $this->redis->absoluteKey('pump:alive');
        $pid = pcntl_fork();
        if (-1 === $pid) {
            return null;
        }
        if (0 === $pid) {
            $this->child($dsn, $incarnationKey, $aliveKey, $serverId);
        }

        return $pid;
    }

    public function stop(?int $pid): void
    {
        if (null === $pid || $pid <= 0 || !function_exists('posix_kill') || !function_exists('pcntl_waitpid')) {
            return;
        }
        posix_kill($pid, SIGTERM);
        $status = 0;
        pcntl_waitpid($pid, $status);
    }

    private function child(string $dsn, string $incarnationKey, string $aliveKey, string $serverId): never
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            posix_kill(posix_getpid(), SIGKILL);
        });
        $client = new Client($dsn);
        while (true) {
            try {
                $client->set($incarnationKey, $serverId, 'EX', 10);
                $client->set($aliveKey, '1', 'EX', 10);
            } catch (\Throwable) {
                // A missed beat expires. The parent still owns the turn.
            }
            sleep(2);
        }
    }
}

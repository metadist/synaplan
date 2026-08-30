<?php

declare(strict_types=1);

namespace App\Service\Stt;

use App\Service\Infrastructure\RedisService;
use Psr\Log\LoggerInterface;

/**
 * Persists STT sessions. Filesystem is the source of truth (audio appends
 * need flock); Redis mirrors metadata so a node can list sessions.
 */
final class SttSessionStore
{
    /**
     * Per-process re-entrancy table so append → commit → save on the same
     * session does not deadlock. flock() is not re-entrant across fds.
     *
     * @var array<string, resource>
     */
    private array $heldLocks = [];

    public function __construct(
        private readonly RedisService $redis,
        private readonly LoggerInterface $logger,
        private readonly string $storageDir,
        private readonly int $ttlSeconds = 7200,
    ) {
    }

    public function sessionDir(string $sessionId): string
    {
        return $this->storageDir.'/'.$this->safeId($sessionId);
    }

    public function save(SttSession $session): void
    {
        $now = time();
        $session->updatedAt = $now;
        $session->expiresAt = $now + $this->ttlSeconds;

        $dir = $this->sessionDir($session->id);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create STT session directory');
        }

        $json = json_encode($session->toStorageArray(), JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            throw new \RuntimeException('Failed to encode STT session');
        }

        $this->withLock($session->id, function () use ($dir, $json): void {
            if (false === file_put_contents($dir.'/session.json', $json, LOCK_EX)) {
                throw new \RuntimeException('Failed to write STT session');
            }
        });

        $this->indexAdd($session);
        $this->redis->set($this->redisKey($session->id), $json, $this->ttlSeconds);
        $this->redis->sAdd($this->apiKeyIndexKey($session->apiKeyId), $session->id);
        $this->redis->expire($this->apiKeyIndexKey($session->apiKeyId), $this->ttlSeconds);
        $this->redis->sAdd($this->clientIndexKey($session->apiKeyId, $session->clientId), $session->id);
        $this->redis->expire($this->clientIndexKey($session->apiKeyId, $session->clientId), $this->ttlSeconds);
    }

    public function get(string $sessionId): ?SttSession
    {
        $path = $this->sessionDir($sessionId).'/session.json';
        $raw = is_file($path) ? file_get_contents($path) : null;
        if (!is_string($raw) || '' === $raw) {
            $raw = $this->redis->get($this->redisKey($sessionId));
        }
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $session = SttSession::fromStorageArray($decoded);
        if ('' === $session->id || $session->isExpired(time())) {
            return null;
        }

        return $session;
    }

    /**
     * @return list<SttSession>
     */
    public function listForApiKey(int $apiKeyId, ?string $clientId = null): array
    {
        $ids = $this->indexIds($apiKeyId, $clientId);
        $sessions = [];
        foreach ($ids as $id) {
            $session = $this->get($id);
            if (null === $session) {
                continue;
            }
            if (null !== $clientId && $session->clientId !== $clientId) {
                continue;
            }
            $sessions[] = $session;
        }

        usort($sessions, static fn (SttSession $a, SttSession $b): int => $b->updatedAt <=> $a->updatedAt);

        return $sessions;
    }

    public function findOpenForClient(int $apiKeyId, string $clientId): ?SttSession
    {
        foreach ($this->listForApiKey($apiKeyId, $clientId) as $session) {
            if ($session->isOpen()) {
                return $session;
            }
        }

        return null;
    }

    public function countOpenForApiKey(int $apiKeyId): int
    {
        $count = 0;
        foreach ($this->listForApiKey($apiKeyId) as $session) {
            if ($session->isOpen()) {
                ++$count;
            }
        }

        return $count;
    }

    public function deleteAudio(string $sessionId): void
    {
        $pending = $this->sessionDir($sessionId).'/pending.bin';
        if (is_file($pending)) {
            unlink($pending);
        }
    }

    /**
     * Serialize work on one session. Re-entrant in-process so append → commit
     * → save does not deadlock.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(string $sessionId, callable $callback): mixed
    {
        $id = $this->safeId($sessionId);
        if (isset($this->heldLocks[$id])) {
            return $callback();
        }

        $dir = $this->sessionDir($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create STT session directory');
        }

        $handle = fopen($dir.'/session.lock', 'c');
        if (false === $handle) {
            throw new \RuntimeException('Failed to open STT session lock');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Failed to lock STT session');
        }

        $this->heldLocks[$id] = $handle;
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            unset($this->heldLocks[$id]);
        }
    }

    private function redisKey(string $sessionId): string
    {
        return 'stt:session:'.$this->safeId($sessionId);
    }

    private function apiKeyIndexKey(int $apiKeyId): string
    {
        return 'stt:apikey:'.$apiKeyId.':sessions';
    }

    private function clientIndexKey(int $apiKeyId, string $clientId): string
    {
        return 'stt:apikey:'.$apiKeyId.':client:'.$this->safeId($clientId);
    }

    private function safeId(string $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._:-]/', '_', $id) ?? '';

        return '' === $safe ? 'invalid' : $safe;
    }

    private function indexAdd(SttSession $session): void
    {
        $this->writeIndex($this->apiKeyIndexPath($session->apiKeyId), $session->id);
        $this->writeIndex($this->clientIndexPath($session->apiKeyId, $session->clientId), $session->id);
    }

    /**
     * @return list<string>
     */
    private function indexIds(int $apiKeyId, ?string $clientId): array
    {
        $fromRedis = null !== $clientId
            ? $this->redis->sMembers($this->clientIndexKey($apiKeyId, $clientId))
            : $this->redis->sMembers($this->apiKeyIndexKey($apiKeyId));

        $fromFile = $this->readIndex(
            null !== $clientId
                ? $this->clientIndexPath($apiKeyId, $clientId)
                : $this->apiKeyIndexPath($apiKeyId)
        );

        return array_values(array_unique([...$fromRedis, ...$fromFile]));
    }

    private function apiKeyIndexPath(int $apiKeyId): string
    {
        return $this->storageDir.'/index/key-'.$apiKeyId.'.json';
    }

    private function clientIndexPath(int $apiKeyId, string $clientId): string
    {
        return $this->storageDir.'/index/key-'.$apiKeyId.'-client-'.$this->safeId($clientId).'.json';
    }

    /**
     * @return list<string>
     */
    private function readIndex(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (is_string($id) && '' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function writeIndex(string $path, string $sessionId): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning('STT session index directory could not be created', ['path' => $dir]);

            return;
        }

        $handle = fopen($path.'.lock', 'c');
        if (false === $handle) {
            $this->logger->warning('STT session index lock could not be opened', ['path' => $path]);

            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Failed to lock STT session index');
            }

            $ids = $this->readIndex($path);
            if (!in_array($sessionId, $ids, true)) {
                $ids[] = $sessionId;
            }

            if (false === file_put_contents($path, json_encode($ids, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX)) {
                throw new \RuntimeException('Failed to write STT session index');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

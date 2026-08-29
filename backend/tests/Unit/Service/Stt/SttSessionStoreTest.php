<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stt;

use App\Service\Infrastructure\RedisService;
use App\Service\Stt\SttSession;
use App\Service\Stt\SttSessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SttSessionStoreTest extends TestCase
{
    private string $dir;
    private SttSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stt-store-'.bin2hex(random_bytes(4));
        $redis = new RedisService('', 'test', new NullLogger());
        $this->store = new SttSessionStore($redis, new NullLogger(), $this->dir, 3600);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testSaveAndGetRoundTrip(): void
    {
        $session = $this->session('stt_sess_aaa', '123', 1);
        $this->store->save($session);

        $loaded = $this->store->get('stt_sess_aaa');
        $this->assertNotNull($loaded);
        $this->assertSame('123', $loaded->clientId);
        $this->assertSame(1, $loaded->apiKeyId);
        $this->assertSame('whisper', $loaded->model);
        $this->assertTrue($loaded->isOpen());
    }

    public function testListIsScopedToApiKeyAndClient(): void
    {
        $this->store->save($this->session('stt_sess_a', '123', 1));
        $this->store->save($this->session('stt_sess_b', '321', 1));
        $this->store->save($this->session('stt_sess_c', '123', 2));

        $forKey1 = $this->store->listForApiKey(1);
        $this->assertCount(2, $forKey1);
        $clientIds = array_map(static fn (SttSession $s): string => $s->clientId, $forKey1);
        sort($clientIds);
        $this->assertSame(['123', '321'], $clientIds);

        $client123 = $this->store->listForApiKey(1, '123');
        $this->assertCount(1, $client123);
        $this->assertSame('stt_sess_a', $client123[0]->id);
    }

    public function testFindOpenForClientIgnoresClosed(): void
    {
        $open = $this->session('stt_sess_open', '123', 1);
        $closed = $this->session('stt_sess_closed', '123', 1);
        $closed->status = SttSession::STATUS_CLOSED;
        $this->store->save($closed);
        $this->store->save($open);

        $found = $this->store->findOpenForClient(1, '123');
        $this->assertNotNull($found);
        $this->assertSame('stt_sess_open', $found->id);
    }

    public function testMissingSessionReturnsNull(): void
    {
        $this->assertNull($this->store->get('stt_sess_missing'));
    }

    private function session(string $id, string $clientId, int $apiKeyId): SttSession
    {
        $now = time();

        return new SttSession(
            id: $id,
            clientId: $clientId,
            apiKeyId: $apiKeyId,
            userId: 9,
            model: 'whisper',
            provider: 'whisper',
            modelId: 330,
            language: 'en',
            prompt: null,
            status: SttSession::STATUS_OPEN,
            encoding: 'pcm_s16le',
            sampleRate: 16000,
            channels: 1,
            commitAfterBytes: 96000,
            createdAt: $now,
            updatedAt: $now,
            expiresAt: $now + 3600,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

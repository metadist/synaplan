<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\MessagesContextInjector;
use App\Entity\User;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\RAG\VectorSearchService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

final class MessagesContextInjectorTest extends TestCase
{
    public function testAppendsTrailingSystemBlockAndIsSessionStable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $memory = $this->createMock(UserMemoryService::class);
        $memory->expects($this->once())->method('embedUserQuery')->willReturn([
            'embedding' => [0.1, 0.2],
            'model_id' => 1,
            'model_name' => 'bge',
            'provider' => 'ollama',
        ]);
        $memory->method('embedQueryForMemorySearch')->willReturn([
            'embedding' => [0.1, 0.2],
            'model_id' => 1,
            'model_name' => 'bge',
            'provider' => 'ollama',
        ]);
        $memory->method('searchMemoriesByVector')->willReturn([
            ['id' => 1, 'key' => 'pref', 'value' => 'dark'],
        ]);

        $vector = $this->createMock(VectorSearchService::class);
        $vector->method('semanticSearchByVector')->willReturn([
            ['id' => 5, 'chunk_text' => 'kb hit'],
        ]);

        /** @var mixed $stored written by reference in the set() stub below */
        $stored = null;
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturnCallback(function () use (&$stored): bool {
            return null !== $stored;
        });
        $item->method('get')->willReturnCallback(function () use (&$stored) {
            return $stored;
        });
        $item->method('set')->willReturnCallback(function ($v) use (&$stored, $item) {
            $stored = $v;

            return $item;
        });
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(true);

        $injector = new MessagesContextInjector(
            $memory,
            $vector,
            new KnowledgeContextFormatter(),
            $cache,
            new NullLogger(),
        );

        $body = [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 16,
            'system' => 'You are helpful.',
            'messages' => [['role' => 'user', 'content' => 'hello world']],
        ];

        $first = $injector->inject($body, $user, 'sess-1');
        $this->assertTrue($first['injected']);
        $this->assertIsString($first['hash']);
        $this->assertIsArray($first['body']['system']);
        $this->assertSame('You are helpful.', $first['body']['system'][0]['text']);
        $this->assertStringContainsString('Knowledge Base Context', $first['body']['system'][1]['text']);
        $this->assertStringContainsString('User Memories', $first['body']['system'][1]['text']);

        $second = $injector->inject($body, $user, 'sess-1');
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame($first['body']['system'][1]['text'], $second['body']['system'][1]['text']);
    }

    public function testHeaderOffSkipsInjection(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $injector = new MessagesContextInjector(
            $this->createMock(UserMemoryService::class),
            $this->createMock(VectorSearchService::class),
            new KnowledgeContextFormatter(),
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
        );

        $body = [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ];
        $result = $injector->inject($body, $user, 's', 'off');
        $this->assertFalse($result['injected']);
        $this->assertSame($body, $result['body']);
    }
}

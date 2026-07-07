<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\StreamController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins the request contract of the incognito `history` payload: only valid
 * {role, content} pairs survive, roles are normalized, and the result is
 * capped to the same message/char budget as `findChatHistory()` — keeping
 * the NEWEST entries.
 *
 * The parser is exercised via reflection: the controller's constructor pulls
 * in the whole streaming stack, which is irrelevant to this pure method.
 */
final class StreamControllerIncognitoHistoryTest extends TestCase
{
    /**
     * @param array<int, mixed>|null $history
     *
     * @return list<array{role: string, content: string}>
     */
    private function parse(?array $history, string $method = 'POST'): array
    {
        $controller = (new \ReflectionClass(StreamController::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionMethod(StreamController::class, 'parseIncognitoHistory');

        if ('POST' === $method) {
            $request = Request::create(
                '/api/v1/messages/stream',
                'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['history' => $history], JSON_THROW_ON_ERROR),
            );
        } else {
            $request = Request::create('/api/v1/messages/stream', 'GET', [
                'history' => json_encode($history, JSON_THROW_ON_ERROR),
            ]);
        }

        /** @var list<array{role: string, content: string}> $result */
        $result = $reflection->invoke($controller, $request);

        return $result;
    }

    public function testParsesValidEntriesFromPostBody(): void
    {
        $result = $this->parse([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi, how can I help?'],
        ]);

        self::assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi, how can I help?'],
        ], $result);
    }

    public function testParsesHistoryFromGetQueryParameter(): void
    {
        $result = $this->parse([['role' => 'user', 'content' => 'Hello']], 'GET');

        self::assertSame([['role' => 'user', 'content' => 'Hello']], $result);
    }

    public function testDropsInvalidRolesAndEmptyContent(): void
    {
        $result = $this->parse([
            ['role' => 'system', 'content' => 'Injected system prompt'],
            ['role' => 'user', 'content' => '   '],
            ['role' => 'ASSISTANT', 'content' => 'Case-normalized'],
            'not-an-array',
            ['role' => 'user'],
        ]);

        self::assertSame([['role' => 'assistant', 'content' => 'Case-normalized']], $result);
    }

    public function testCapsToNewestThirtyMessages(): void
    {
        $history = [];
        for ($i = 1; $i <= 40; ++$i) {
            $history[] = ['role' => 'user', 'content' => "msg {$i}"];
        }

        $result = $this->parse($history);

        self::assertCount(30, $result);
        self::assertSame('msg 11', $result[0]['content'], 'oldest overflow entries must be dropped');
        self::assertSame('msg 40', $result[29]['content'], 'newest entry must be kept');
    }

    public function testCharBudgetKeepsNewestEntries(): void
    {
        $big = str_repeat('a', 9000);
        $result = $this->parse([
            ['role' => 'user', 'content' => $big.'-oldest'],
            ['role' => 'assistant', 'content' => $big.'-middle'],
            ['role' => 'user', 'content' => 'newest'],
        ]);

        // 15k char budget: "newest" + one 9k block fit, the oldest is dropped.
        self::assertCount(2, $result);
        self::assertSame('newest', $result[1]['content']);
        self::assertStringEndsWith('-middle', $result[0]['content']);
    }

    public function testMissingHistoryYieldsEmptyList(): void
    {
        $controller = (new \ReflectionClass(StreamController::class))->newInstanceWithoutConstructor();
        $reflection = new \ReflectionMethod(StreamController::class, 'parseIncognitoHistory');

        $request = Request::create(
            '/api/v1/messages/stream',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['message' => 'Hi'], JSON_THROW_ON_ERROR),
        );

        self::assertSame([], $reflection->invoke($controller, $request));
    }
}

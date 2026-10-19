<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiIssuedKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreeApiIssuedKeyTest extends TestCase
{
    public function testAValidAnswerIsRead(): void
    {
        $issued = FreeApiIssuedKey::fromResponse(self::answer());

        $this->assertSame('sk-free-abc123', $issued->key);
        $this->assertSame(42, $issued->keyId);
        $this->assertSame(14, $issued->daysLeft);
        $this->assertSame(['messages_per_day' => 40, 'cost_per_day_usd' => 0.25], $issued->limits);
        $this->assertSame([['id' => 'free-chat', 'name' => 'Synaplan Demo · GPT OSS 120B']], $issued->aliases);
        $this->assertSame(1, $issued->protocolMin);
    }

    public function testMalformedEntriesAreDroppedNotTrusted(): void
    {
        $issued = FreeApiIssuedKey::fromResponse(self::answer([
            'limits' => ['messages_per_day' => '40', 'images_per_day' => 5, 3 => 9],
            'aliases' => [['id' => 'free-chat'], 'free-sort', ['id' => 'free-embed', 'name' => 'Embed']],
        ]));

        $this->assertSame(['images_per_day' => 5], $issued->limits);
        $this->assertSame([['id' => 'free-embed', 'name' => 'Embed']], $issued->aliases);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenAnswers(): iterable
    {
        yield 'no key' => [['key' => null]];
        yield 'empty key' => [['key' => '']];
        yield 'key with a newline' => [['key' => "sk-free\nX-Injected: 1"]];
        yield 'key too long' => [['key' => str_repeat('a', 257)]];
        yield 'key id as string' => [['key_id' => '42']];
        yield 'no expiry' => [['expires_at' => null]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('brokenAnswers')]
    public function testABrokenAnswerIsRefused(array $override): void
    {
        $this->expectException(FreeApiException::class);
        FreeApiIssuedKey::fromResponse(self::answer($override));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function answer(array $override = []): array
    {
        return array_merge([
            'key' => 'sk-free-abc123',
            'key_id' => 42,
            'expires_at' => 1_800_000_000,
            'days_left' => 14,
            'limits' => ['messages_per_day' => 40, 'cost_per_day_usd' => 0.25],
            'aliases' => [['id' => 'free-chat', 'name' => 'Synaplan Demo · GPT OSS 120B']],
            'protocol_min' => 1,
        ], $override);
    }
}

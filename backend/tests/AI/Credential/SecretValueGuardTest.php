<?php

declare(strict_types=1);

namespace App\Tests\AI\Credential;

use App\AI\Credential\SecretValueGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard decides whether a value that looks like a secret may be stored.
 * It must be strict about template text and lenient about the short dummy keys
 * integration environments use — rejecting those would make every provider
 * unavailable in CI.
 */
final class SecretValueGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function placeholders(): iterable
    {
        yield 'documentation default' => ['your-api-key-here'];
        yield 'snake case variant' => ['YOUR_API_KEY_HERE'];
        yield 'changeme' => ['changeme'];
        yield 'angle bracket template' => ['<your-key>'];
        yield 'unexpanded shell var' => ['${GROQ_API_KEY}'];
        yield 'bare shell var' => ['$GROQ_API_KEY'];
        yield 'filler' => ['xxxxxxxx'];
        yield 'prefixed filler' => ['sk-xxxxxx'];
        yield 'whitespace only' => ['   '];
        yield 'surrounding whitespace' => ["  changeme\n"];
    }

    #[DataProvider('placeholders')]
    public function testPlaceholdersAreNotUsable(string $value): void
    {
        self::assertTrue(SecretValueGuard::isPlaceholder($value));
        self::assertFalse(SecretValueGuard::isUsable($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realValues(): iterable
    {
        yield 'groq key' => ['gsk_2f8Xa9Qm10ZbT7LcVhPq'];
        yield 'openai key' => ['sk-proj-AbC123dEf456'];
        yield 'ci dummy key' => ['test-key'];
        yield 'vertex bearer' => ['ya29.a0ARrdaM-abc_def'];
        yield 'short but real' => ['abc123'];
    }

    #[DataProvider('realValues')]
    public function testRealValuesAreUsable(string $value): void
    {
        self::assertFalse(SecretValueGuard::isPlaceholder($value));
        self::assertTrue(SecretValueGuard::isUsable($value));
    }

    public function testNullAndEmptyAreNotUsable(): void
    {
        self::assertFalse(SecretValueGuard::isUsable(null));
        self::assertFalse(SecretValueGuard::isUsable(''));
        self::assertTrue(SecretValueGuard::isPlaceholder(null));
    }

    public function testMaskedValuesAreDetected(): void
    {
        self::assertTrue(SecretValueGuard::isMasked('••••••••'));
        self::assertTrue(SecretValueGuard::isMasked('gsk_••••••••••••abcd'));
        self::assertFalse(SecretValueGuard::isMasked('gsk_real_value'));
        self::assertFalse(SecretValueGuard::isUsable('gsk_••••••••••••abcd'));
    }
}

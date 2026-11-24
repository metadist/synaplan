<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\VectorSearch;

use App\Service\VectorSearch\QdrantApiKey;
use PHPUnit\Framework\TestCase;

final class QdrantApiKeyTest extends TestCase
{
    public function testAnEmptyOrBlankKeyAddsNothing(): void
    {
        $headers = ['Content-Type' => 'application/json'];

        self::assertSame($headers, (new QdrantApiKey(''))->apply($headers));
        self::assertSame($headers, (new QdrantApiKey('   '))->apply($headers));
        self::assertFalse((new QdrantApiKey('   '))->isSet());
    }

    public function testASetKeyIsTheApiKeyHeaderAndNotTheCacheMaterial(): void
    {
        $key = new QdrantApiKey('  qdrant-test-key-not-for-logs  ');

        self::assertSame(
            ['Content-Type' => 'application/json', 'api-key' => 'qdrant-test-key-not-for-logs'],
            $key->apply(['Content-Type' => 'application/json']),
        );
        self::assertNotSame('', $key->cacheDiscriminator());
        self::assertStringNotContainsString('qdrant-test-key-not-for-logs', $key->cacheDiscriminator());
    }

    public function testFailureMessagesNameTheSettingAndHideTheKey(): void
    {
        $key = new QdrantApiKey('qdrant-test-key-not-for-logs');

        self::assertStringContainsString('Set QDRANT_API_KEY', (string) (new QdrantApiKey(''))->failureMessage(401));
        self::assertStringContainsString('rejected the API key', (string) $key->failureMessage(401, 'Invalid API key or JWT qdrant-test-key-not-for-logs'));
        $forbidden = (string) $key->failureMessage(403, 'body mentions qdrant-test-key-not-for-logs');
        self::assertStringContainsString('HTTP 403', $forbidden);
        self::assertStringNotContainsString('qdrant-test-key-not-for-logs', $forbidden);
        self::assertStringContainsString('[redacted]', $forbidden);
        self::assertNull($key->failureMessage(500));
        self::assertSame($key->failureMessage(401), $key->messageForProbe(QdrantApiKey::PROBE_UNAUTHORIZED));
        self::assertNull($key->messageForProbe(QdrantApiKey::PROBE_DOWN));
    }
}

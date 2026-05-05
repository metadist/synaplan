<?php

declare(strict_types=1);

namespace App\Tests\Service\VectorSearch;

use App\Service\VectorSearch\QdrantPointId;
use PHPUnit\Framework\TestCase;

/**
 * @see QdrantPointId
 */
final class QdrantPointIdTest extends TestCase
{
    /**
     * Cross-check against a UUID computed outside the codebase to detect
     * any future accidental namespace change.
     */
    public function testUuidForIsDeterministicAndMatchesKnownValue(): void
    {
        // Hand-computed against the canonical DNS namespace
        // 6ba7b810-9dad-11d1-80b4-00c04fd430c8 with name "mem_152_1769723312080348".
        $this->assertSame(
            'dd188347-154f-55e4-9c69-699010e7828a',
            QdrantPointId::uuidFor('mem_152_1769723312080348')
        );

        // Stable across calls
        $this->assertSame(
            QdrantPointId::uuidFor('mem_1_42'),
            QdrantPointId::uuidFor('mem_1_42'),
        );
    }

    public function testPayloadFilterForMatchesOnPointIdKey(): void
    {
        $filter = QdrantPointId::payloadFilterFor('mem_1_42');

        $this->assertSame([
            'must' => [
                ['key' => '_point_id', 'match' => ['value' => 'mem_1_42']],
            ],
        ], $filter);
    }

    public function testIsCanonicalUuidRejectsLegacyIntegerPrimary(): void
    {
        $this->assertFalse(
            QdrantPointId::isCanonicalUuid(82402287705672322, 'mem_152_1769723312080348'),
        );
    }

    public function testIsCanonicalUuidAcceptsCorrectlyDerivedUuid(): void
    {
        $logical = 'mem_1_42';
        $this->assertTrue(
            QdrantPointId::isCanonicalUuid(QdrantPointId::uuidFor($logical), $logical),
        );
    }

    /**
     * A UUID that is syntactically valid but NOT the one we would derive
     * for this logical ID must NOT be treated as canonical — otherwise a
     * hand-written UUIDv4 could mask a migration-needed state.
     */
    public function testIsCanonicalUuidRejectsUnrelatedUuid(): void
    {
        // Random UUIDv4, not derived from the namespace
        $this->assertFalse(
            QdrantPointId::isCanonicalUuid('550e8400-e29b-41d4-a716-446655440000', 'mem_1_42'),
        );
    }
}

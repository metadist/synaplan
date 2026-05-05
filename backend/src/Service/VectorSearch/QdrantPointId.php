<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Symfony\Component\Uid\Uuid;

/**
 * Helper for translating synaplan-logical point IDs (`mem_{userId}_{memoryId}`,
 * `doc_{userId}_{fileId}_{chunk}`) into the canonical Qdrant primary ID
 * (UUIDv5 under the fixed namespace) and into the payload filter we use for
 * "find this logical point regardless of how it was originally keyed".
 *
 * Shared between QdrantClientDirect (runtime read/write) and
 * {@see \App\Command\MigrateLegacyPointIdsCommand} (one-shot cleanup) so
 * neither can drift and produce mismatched UUIDs.
 */
final class QdrantPointId
{
    /**
     * UUIDv5 namespace for point-ID derivation.
     *
     * This is the canonical DNS namespace from RFC 4122 §Appendix C —
     * {@see https://www.rfc-editor.org/rfc/rfc4122#appendix-C}. Changing
     * this value breaks mapping of every existing stored point; DO NOT
     * change it without a full data migration.
     */
    public const NAMESPACE_UUID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /** Cached parsed namespace to avoid re-parsing on every call. */
    private static ?Uuid $namespaceUuid = null;

    /**
     * Deterministic UUIDv5 primary key for a logical point ID.
     *
     * Same input always produces the same UUID, so callers can safely
     * recompute it instead of storing it alongside the logical ID.
     */
    public static function uuidFor(string $logicalPointId): string
    {
        if (null === self::$namespaceUuid) {
            self::$namespaceUuid = Uuid::fromString(self::NAMESPACE_UUID);
        }

        return Uuid::v5(self::$namespaceUuid, $logicalPointId)->toRfc4122();
    }

    /**
     * Qdrant filter that matches the point whose `_point_id` payload
     * equals `$logicalPointId` — the canonical logical identifier that
     * works for both legacy (integer-keyed) and current (UUID-keyed) points.
     *
     * @return array{must: list<array{key: string, match: array{value: string}}>}
     */
    public static function payloadFilterFor(string $logicalPointId): array
    {
        return [
            'must' => [
                ['key' => '_point_id', 'match' => ['value' => $logicalPointId]],
            ],
        ];
    }

    /**
     * Check whether a Qdrant primary ID is already in canonical UUIDv5
     * form AND matches the UUID we would derive from `_point_id`.
     *
     * Used by the migration command to identify points that still need
     * to be rekeyed. Unlike a generic UUID-format check, this also
     * rejects UUIDv4 or any other derivation, so only points that
     * exactly match the current scheme are considered "canonical".
     */
    public static function isCanonicalUuid(int|string $primaryId, string $logicalPointId): bool
    {
        if (!is_string($primaryId)) {
            return false;
        }

        return 0 === strcasecmp($primaryId, self::uuidFor($logicalPointId));
    }
}

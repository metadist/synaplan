<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of user-owned item that Smart Search indexes (chats, files, …).
 *
 * The source is the single place that knows how the item maps to a
 * {@see SearchDocument}, which entity changes make the row stale, and how a
 * hit is turned back into a live, permission-checked result.
 */
#[AutoconfigureTag('app.smart_search.source')]
interface SearchDocumentSourceInterface
{
    public function kind(): string;

    /** Whether the kind is visible to this user right now (feature flags). */
    public function isEnabledFor(int $userId): bool;

    /**
     * Entity changes that stale the index. `null` means the source does not
     * track this entity; otherwise the owner and item id to refresh.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet empty for inserts and deletions
     *
     * @return array{userId: int, refId: string}|null
     */
    public function refFor(object $entity, array $changeSet): ?array;

    /** The current document, or null when the item is gone or not searchable. */
    public function build(int $userId, string $refId): ?SearchDocument;

    /** @return iterable<SearchDocument> */
    public function allForUser(int $userId): iterable;

    /**
     * Live view of the hits the user may still open, keyed by ref id. Ids the
     * user cannot access (or that no longer exist) are absent.
     *
     * @param list<string> $refIds
     *
     * @return array<string, ResolvedItem>
     */
    public function resolve(int $userId, array $refIds): array;
}

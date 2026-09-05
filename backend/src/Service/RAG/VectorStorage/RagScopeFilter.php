<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;

/**
 * Builds MariaDB WHERE fragments and Qdrant filter documents from a SearchQuery.
 *
 * The own-only (legacy) shape is frozen: a single owner scope with no file list
 * must emit today's SQL and today's `{filter: {must: […]}}` — never `should`.
 */
final class RagScopeFilter
{
    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    public static function mariaDbWhere(SearchQuery $query): array
    {
        if ($query->isLegacyOwnFilter()) {
            $sql = 'r.BUID = :userId';
            $params = ['userId' => $query->userId];
            if (null !== $query->groupKey) {
                $sql .= ' AND r.BGROUPKEY = :groupKey';
                $params['groupKey'] = $query->groupKey;
            }

            return ['sql' => $sql, 'params' => $params];
        }

        $parts = [];
        $params = [];
        foreach ($query->scopes as $i => $scope) {
            $parts[] = self::mariaDbScope($scope, $i, $params);
        }

        return ['sql' => '('.implode(' OR ', $parts).')', 'params' => $params];
    }

    /**
     * Qdrant filter object (the value of `filter`, not wrapped again).
     *
     * @return array<string, mixed>
     */
    public static function qdrant(SearchQuery $query): array
    {
        if ($query->isLegacyOwnFilter()) {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $query->userId]],
            ];
            if (null !== $query->groupKey) {
                $must[] = ['key' => 'group_key', 'match' => ['value' => $query->groupKey]];
            }

            return ['must' => $must];
        }

        $should = [];
        foreach ($query->scopes as $scope) {
            $must = [
                ['key' => 'user_id', 'match' => ['value' => $scope->ownerId]],
            ];
            if (null !== $scope->groupKey) {
                $must[] = ['key' => 'group_key', 'match' => ['value' => $scope->groupKey]];
            }
            if ([] !== $scope->fileIds) {
                $must[] = ['key' => 'file_id', 'match' => ['any' => $scope->fileIds]];
            }
            $should[] = ['must' => $must];
        }

        return ['should' => $should];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function mariaDbScope(RagScope $scope, int $i, array &$params): string
    {
        $ownerKey = 'u'.$i;
        $sql = 'r.BUID = :'.$ownerKey;
        $params[$ownerKey] = $scope->ownerId;
        if (null !== $scope->groupKey) {
            $groupParam = 'g'.$i;
            $sql .= ' AND r.BGROUPKEY = :'.$groupParam;
            $params[$groupParam] = $scope->groupKey;
        }
        if ([] !== $scope->fileIds) {
            $ids = implode(',', array_map(static fn (int $id): string => (string) $id, $scope->fileIds));
            $sql .= ' AND r.BMID IN ('.$ids.')';
        }

        return '('.$sql.')';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\File;

use Symfony\Component\HttpFoundation\Request;

/**
 * Query string shared by GET /api/v1/files and GET /api/v1/files/groups.
 */
final class FileListingQuery
{
    /**
     * @return array{search: ?string, file_type: ?string, source: ?string, exclude_source: ?string, vector_state: ?string, origin_kind: ?string, incoming: ?bool, sort: ?string, date_from: ?int, date_to: ?int}
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'search' => self::optionalString($request, 'search'),
            'file_type' => self::optionalString($request, 'file_type'),
            'source' => self::optionalString($request, 'source'),
            'exclude_source' => self::optionalString($request, 'exclude_source'),
            'vector_state' => self::optionalString($request, 'vector_state'),
            'origin_kind' => self::optionalString($request, 'origin_kind'),
            'incoming' => $request->query->has('incoming') ? $request->query->getBoolean('incoming') : null,
            'sort' => self::optionalString($request, 'sort'),
            'date_from' => $request->query->getInt('date_from') ?: null,
            'date_to' => $request->query->getInt('date_to') ?: null,
        ];
    }

    /**
     * exclude_source is the library default (hide AI-created files). It must
     * not hide vector-only folders, which have no file row to match.
     *
     * @param array{search?: ?string, file_type?: ?string, source?: ?string, exclude_source?: ?string, vector_state?: ?string, origin_kind?: ?string, incoming?: ?bool, date_from?: ?int, date_to?: ?int} $filters
     */
    public static function isNarrowed(array $filters): bool
    {
        foreach (['search', 'file_type', 'source', 'vector_state', 'origin_kind'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return true;
            }
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            return true;
        }

        return null !== ($filters['incoming'] ?? null);
    }

    private static function optionalString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }
}

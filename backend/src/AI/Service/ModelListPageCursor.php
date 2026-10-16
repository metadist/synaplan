<?php

declare(strict_types=1);

namespace App\AI\Service;

/**
 * Next-page URL for provider model-list responses.
 *
 * Google uses {@code nextPageToken}; Anthropic uses {@code has_more} + {@code last_id}.
 */
final class ModelListPageCursor
{
    /**
     * @param array<mixed> $body decoded list response body
     */
    public static function nextUrl(string $baseUrl, array $body): ?string
    {
        $nextPage = is_string($body['nextPageToken'] ?? null) && '' !== $body['nextPageToken']
            ? $body['nextPageToken']
            : null;
        if (null !== $nextPage) {
            return $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'pageToken='.rawurlencode($nextPage);
        }

        $hasMore = true === ($body['has_more'] ?? false);
        $lastId = is_string($body['last_id'] ?? null) && '' !== $body['last_id']
            ? $body['last_id']
            : null;
        if ($hasMore && null !== $lastId) {
            return $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'after_id='.rawurlencode($lastId);
        }

        return null;
    }
}

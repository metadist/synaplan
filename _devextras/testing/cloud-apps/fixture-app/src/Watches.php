<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * The app side of watched folders: the instance index, one file upload, and
 * reconciliation. The person's key is passed in; the fixture does not keep it.
 */
final class Watches
{
    public function __construct(private readonly Synaplan $synaplan)
    {
    }

    /**
     * @return array{status: int, body: array<mixed>}
     */
    public function index(?string $cursor, ?string $since): array
    {
        $query = [];
        if (null !== $cursor && '' !== $cursor) {
            $query['cursor'] = $cursor;
        }
        if (null !== $since && '' !== $since) {
            $query['since'] = $since;
        }
        $path = '/api/v1/platform-links/watches';
        if ([] !== $query) {
            $path .= '?'.http_build_query($query);
        }

        return $this->synaplan->json('GET', $path);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function upload(string $apiKey, int $watchId, array $fields, string $bytes, string $name): array
    {
        return $this->synaplan->upload(
            '/api/v1/cloud/watches/'.$watchId.'/files',
            $fields,
            $bytes,
            $name,
            'application/octet-stream',
            ['X-Api-Key' => $apiKey],
        );
    }

    /**
     * @param list<array{id: string, version: string}> $items
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function reconcile(string $apiKey, int $watchId, array $items): array
    {
        return $this->synaplan->json(
            'POST',
            '/api/v1/cloud/watches/'.$watchId.'/reconcile',
            ['items' => $items],
            ['X-Api-Key' => $apiKey],
        );
    }
}

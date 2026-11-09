<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * The app side of watched folders: a person's watches, one file upload, and
 * reconciliation. The person's key is passed in; the fixture does not keep it.
 */
final class Watches
{
    public function __construct(private readonly Synaplan $synaplan)
    {
    }

    /**
     * The person's own watches on this cloud. There is no index across
     * people: the app asks for each person with that person's key.
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function list(string $accessToken): array
    {
        return $this->synaplan->json('GET', '/api/v1/cloud/watches', null, ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function upload(string $accessToken, int $watchId, array $fields, string $bytes, string $name): array
    {
        return $this->synaplan->upload(
            '/api/v1/cloud/watches/'.$watchId.'/files',
            $fields,
            $bytes,
            $name,
            'application/octet-stream',
            ['Authorization' => 'Bearer '.$accessToken],
        );
    }

    /**
     * @param list<array{id: string, version: string}> $items
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function reconcile(string $accessToken, int $watchId, array $items): array
    {
        return $this->synaplan->json(
            'POST',
            '/api/v1/cloud/watches/'.$watchId.'/reconcile',
            ['items' => $items],
            ['Authorization' => 'Bearer '.$accessToken],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\CloudWatchRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * The page of watches an app keeps locally so everyone who can open a folder
 * sees the eyes, without one request per row.
 */
final readonly class CloudWatchIndex
{
    public function __construct(
        private CloudWatchRepository $watches,
        private ExternalIdentityRepository $identities,
        private UserRepository $users,
        private WatchSentence $sentences,
    ) {
    }

    /**
     * @return array{status: int, etag: string, body: array<string, mixed>|null}
     */
    public function feed(PlatformInstance $instance, Request $request, int $now): array
    {
        $limit = $this->limit($request);
        $etag = '"'.$this->watches->highWater($instance->getInstanceId()).'"';
        $since = $request->query->get('since');
        $cursor = $request->query->get('cursor');
        if (is_string($since) && '' !== $since && is_string($cursor) && '' !== $cursor) {
            throw new CloudWatchException(400, 'Ask for a page or for changes, not both.');
        }
        if (is_string($since) && '' !== $since) {
            return $this->changes($instance, $since, $limit, $etag, $now);
        }
        $after = 0;
        if (is_string($cursor) && '' !== $cursor) {
            $read = WatchIndexCursor::read($cursor, $now);
            if (null === $read || 'p' !== $read['kind']) {
                return ['status' => 410, 'etag' => $etag, 'body' => ['error' => 'cursor_expired']];
            }
            $after = $read['id'];
        }
        $page = $this->watches->page($instance->getInstanceId(), $after, $limit);
        $more = count($page) === $limit;
        $last = $page[array_key_last($page)] ?? null;
        $next = $more && $last instanceof CloudWatch
            ? WatchIndexCursor::page($now, (int) $last->getId())
            : WatchIndexCursor::since($now, $this->watches->highWater($instance->getInstanceId()), (int) ($last instanceof CloudWatch ? $last->getId() : $after));
        $body = [
            'items' => $this->present($page, false),
            'tombstones' => [],
            'next_cursor' => $next,
        ];
        if ($request->headers->get('If-None-Match') === $etag && 0 === $after) {
            return ['status' => 304, 'etag' => $etag, 'body' => null];
        }

        return ['status' => 200, 'etag' => $etag, 'body' => $body];
    }

    /**
     * @return array{status: int, etag: string, body: array<string, mixed>|null}
     */
    private function changes(PlatformInstance $instance, string $cursor, int $limit, string $etag, int $now): array
    {
        $read = WatchIndexCursor::read($cursor, $now);
        if (null === $read) {
            return ['status' => 410, 'etag' => $etag, 'body' => ['error' => 'cursor_expired']];
        }
        $rows = $this->watches->changedSince($instance->getInstanceId(), $read['updated'], $read['id'], $limit);
        if ([] === $rows) {
            return ['status' => 304, 'etag' => $etag, 'body' => null];
        }
        $last = $rows[array_key_last($rows)];

        return [
            'status' => 200,
            'etag' => $etag,
            'body' => [
                'items' => $this->present($rows, false),
                'tombstones' => $this->tombstones($rows),
                'next_cursor' => WatchIndexCursor::since($now, $last->getUpdated(), (int) $last->getId()),
            ],
        ];
    }

    /** @param list<CloudWatch> $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows, bool $includeStopped): array
    {
        $names = $this->names($rows);
        $owners = $this->owners($rows);
        $items = [];
        foreach ($rows as $watch) {
            if (!$includeStopped && CloudWatch::STATE_STOPPED === $watch->getState()) {
                continue;
            }
            $identityId = $watch->getIdentityId();
            $items[] = [
                'id' => (int) $watch->getId(),
                'target_kind' => $watch->getTargetKind(),
                'target_id' => $watch->getTargetId(),
                'owner_external_id' => null !== $identityId ? ($owners[$identityId] ?? '') : '',
                'owner_name' => $names[$watch->getOwnerId()] ?? '',
                'sentence' => $this->sentences->for($watch),
                'state' => $watch->getState(),
                'reason' => $watch->getReason(),
                'updated_at' => $watch->getUpdated(),
            ];
        }

        return $items;
    }

    /**
     * @param list<CloudWatch> $rows
     *
     * @return list<int>
     */
    private function tombstones(array $rows): array
    {
        $ids = [];
        foreach ($rows as $watch) {
            if (CloudWatch::STATE_STOPPED === $watch->getState()) {
                $ids[] = (int) $watch->getId();
            }
        }

        return $ids;
    }

    /**
     * @param list<CloudWatch> $rows
     *
     * @return array<int, string>
     */
    private function names(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (CloudWatch $watch): int => $watch->getOwnerId(), $rows)));
        if ([] === $ids) {
            return [];
        }
        $names = [];
        foreach ($this->users->findBy(['id' => $ids]) as $user) {
            if ($user instanceof User && null !== $user->getId()) {
                $names[(int) $user->getId()] = $user->getMail();
            }
        }

        return $names;
    }

    /**
     * @param list<CloudWatch> $rows
     *
     * @return array<int, string>
     */
    private function owners(array $rows): array
    {
        $ids = [];
        foreach ($rows as $watch) {
            if (null !== $watch->getIdentityId()) {
                $ids[] = $watch->getIdentityId();
            }
        }
        $owners = [];
        foreach (array_unique($ids) as $id) {
            $identity = $this->identities->find($id);
            if ($identity instanceof ExternalIdentity) {
                $owners[(int) $identity->getId()] = $identity->getExternalId();
            }
        }

        return $owners;
    }

    private function limit(Request $request): int
    {
        $limit = (int) $request->query->get('limit', '500');
        if ($limit < 1 || $limit > 500) {
            throw new CloudWatchException(400, 'Ask for between 1 and 500 watches.');
        }

        return $limit;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Repository\UseLogRepository;

/**
 * The admin cost board (Operate → Usage): who produces what cost, ranked,
 * and the individual model requests behind a row.
 *
 * Costs are the provider costs as recorded in BUSELOG.BCOST — what the
 * platform pays, before any markup it charges on. The board shows no
 * message content; a request is a time, a kind, a model and a price.
 */
final readonly class AdminUsageService
{
    public const PERIODS = ['24h', '7d', '30d', 'all'];
    public const SORTS = ['cost', 'requests', 'tokens'];
    public const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private UseLogRepository $useLog,
    ) {
    }

    public static function sinceFor(string $period, ?int $now = null): int
    {
        $now ??= time();

        return match ($period) {
            '24h' => $now - 86_400,
            '7d' => $now - 7 * 86_400,
            '30d' => $now - 30 * 86_400,
            default => 0,
        };
    }

    public static function periodOrDefault(?string $period): string
    {
        return null !== $period && \in_array($period, self::PERIODS, true) ? $period : '30d';
    }

    public static function sortOrDefault(?string $sort): string
    {
        return null !== $sort && \in_array($sort, self::SORTS, true) ? $sort : 'cost';
    }

    public static function pageOrDefault(?int $page): int
    {
        return max(1, $page ?? 1);
    }

    public static function perPageOrDefault(?int $perPage): int
    {
        return max(1, min(self::MAX_PER_PAGE, $perPage ?? self::DEFAULT_PER_PAGE));
    }

    /**
     * @return array{users: list<array<string, mixed>>, totalUsers: int, page: int, perPage: int, totalPages: int, period: string, sort: string}
     */
    public function users(string $period, string $sort, int $page, int $perPage): array
    {
        $since = self::sinceFor($period);
        $result = $this->useLog->topUsersByUsage($since, $sort, $perPage, ($page - 1) * $perPage);
        $totalUsers = $result['totalUsers'];

        $userIds = array_map(static fn (array $row): int => (int) $row['user_id'], $result['rows']);
        $byKind = $this->kindTotalsByUser($userIds, $since);

        $users = [];
        foreach ($result['rows'] as $row) {
            $userId = (int) $row['user_id'];
            $email = (string) ($row['email'] ?? '');
            $users[] = [
                'userId' => $userId,
                'email' => $email,
                'displayName' => self::displayName($row['details'] ?? null, $email),
                'level' => (string) ($row['level'] ?? 'NEW'),
                'accountStatus' => (string) ($row['account_status'] ?? 'active'),
                'requests' => (int) $row['requests'],
                'tokens' => (int) $row['tokens'],
                'promptTokens' => (int) $row['prompt_tokens'],
                'completionTokens' => (int) $row['completion_tokens'],
                'cost' => round((float) $row['cost'], 6),
                'lastActiveAt' => (int) ($row['last_active'] ?? 0),
                'byKind' => $byKind[$userId] ?? [],
            ];
        }

        return [
            'users' => $users,
            'totalUsers' => $totalUsers,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($totalUsers / $perPage)),
            'period' => $period,
            'sort' => $sort,
        ];
    }

    /**
     * @return array{requests: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int, period: string}
     */
    public function requests(int $userId, string $period, int $page, int $perPage): array
    {
        $since = self::sinceFor($period);
        $result = $this->useLog->requestsForUser($userId, $since, $perPage, ($page - 1) * $perPage);

        $requests = [];
        foreach ($result['rows'] as $row) {
            $metadata = self::metadata(is_string($row['metadata'] ?? null) ? $row['metadata'] : null);
            $action = (string) ($row['action'] ?? '');
            $requests[] = [
                'id' => (int) $row['id'],
                'at' => (int) $row['ts'],
                'kind' => UsageKind::fromAction($action, UsageKind::hasAgent($metadata)),
                'action' => $action,
                'channel' => UsageKind::channel($metadata),
                'provider' => (string) ($row['provider'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'tokens' => (int) $row['tokens'],
                'promptTokens' => (int) $row['prompt_tokens'],
                'completionTokens' => (int) $row['completion_tokens'],
                'cachedTokens' => (int) $row['cached_tokens'],
                'cost' => round((float) $row['cost'], 6),
                'latencyMs' => (int) $row['latency'],
                'status' => (string) ($row['status'] ?? ''),
                'estimated' => (bool) ($row['estimated'] ?? false),
                'chatId' => UsageKind::chatId($metadata),
                'agentId' => UsageKind::agentId($metadata),
            ];
        }

        return [
            'requests' => $requests,
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($result['total'] / $perPage)),
            'period' => $period,
        ];
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<array{kind: string, requests: int, tokens: int, cost: float}>>
     */
    private function kindTotalsByUser(array $userIds, int $since): array
    {
        $grouped = [];
        foreach ($this->useLog->actionTotalsForUsers($userIds, $since) as $row) {
            $kind = UsageKind::fromAction((string) $row['action'], (bool) ($row['has_agent'] ?? false));
            $userId = (int) $row['user_id'];
            $grouped[$userId][$kind] ??= ['kind' => $kind, 'requests' => 0, 'tokens' => 0, 'cost' => 0.0];
            $grouped[$userId][$kind]['requests'] += (int) $row['requests'];
            $grouped[$userId][$kind]['tokens'] += (int) $row['tokens'];
            $grouped[$userId][$kind]['cost'] += (float) $row['cost'];
        }

        foreach ($grouped as $userId => $kinds) {
            $list = array_values($kinds);
            foreach ($list as &$kind) {
                $kind['cost'] = round($kind['cost'], 6);
            }
            unset($kind);
            usort($list, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
            $grouped[$userId] = $list;
        }

        return $grouped;
    }

    /**
     * The name on the row: the stored full/display name, else first + last,
     * else the email. Mirrors User::getDisplayName() on the raw BUSERDETAILS
     * JSON so the board does not load a User entity per row.
     */
    private static function displayName(mixed $details, string $email): string
    {
        $decoded = self::metadata(is_string($details) ? $details : null);
        foreach (['full_name', 'fullName', 'display_name'] as $key) {
            if (is_string($decoded[$key] ?? null) && '' !== trim($decoded[$key])) {
                return trim($decoded[$key]);
            }
        }

        $first = $decoded['first_name'] ?? $decoded['firstName'] ?? null;
        $last = $decoded['last_name'] ?? $decoded['lastName'] ?? null;
        $name = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));

        return '' !== $name ? $name : $email;
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(?string $json): array
    {
        if (null === $json || '' === $json) {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }
}

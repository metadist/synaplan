<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Service\Iam\ResourceKind\ChatDisplayTitle;

/**
 * The long-term memory list: one active digest row per key message.
 *
 * Deletion goes through {@see MessageDigestMaintenance} so the Qdrant point
 * is dropped the same way as chat deletion and the per-user cap prune.
 */
final readonly class LongTermMemoryService
{
    public const DEFAULT_LIMIT = 25;
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 100;
    public const MIN_PAGE = 1;

    public function __construct(
        private MessageDigestRepository $digestRepository,
        private MessageDigestMaintenance $maintenance,
        private MessageDigestConfig $config,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     memoriesEnabled: bool,
     *     entries: list<array{
     *         id: int,
     *         title: string,
     *         messageId: int,
     *         chatId: int|null,
     *         chatTitle: string|null,
     *         channel: string,
     *         sourceDate: int,
     *         created: int
     *     }>,
     *     total: int,
     *     page: int,
     *     limit: int
     * }
     */
    public function listEntries(User $user, int $page, int $limit): array
    {
        $userId = $this->userId($user);
        $page = max(self::MIN_PAGE, $page);
        $limit = min(self::MAX_LIMIT, max(self::MIN_LIMIT, $limit));

        return [
            'enabled' => $this->config->isEnabled(),
            'memoriesEnabled' => $user->isMemoriesEnabled(),
            'entries' => array_map(
                $this->presentEntry(...),
                $this->digestRepository->findActivePage($userId, $limit, ($page - 1) * $limit),
            ),
            'total' => $this->digestRepository->countActiveForUser($userId),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function deleteEntry(User $user, int $digestId): bool
    {
        return $this->maintenance->deactivateOwned($this->userId($user), $digestId);
    }

    public function deleteAll(User $user): int
    {
        return $this->maintenance->deactivateAllActive($this->userId($user));
    }

    /**
     * Active entries for the memory export, same order as {@see listEntries()}.
     *
     * @return list<array{title: string, messageId: int, chatId: int|null, channel: string, sourceDate: int}>
     */
    public function exportEntries(int $userId): array
    {
        return array_map(static fn (array $row): array => [
            'title' => $row['title'],
            'messageId' => $row['messageId'],
            'chatId' => self::chatIdOrNull($row['chatId']),
            'channel' => $row['channel'],
            'sourceDate' => $row['sourceDate'],
        ], $this->digestRepository->findActiveForExport($userId));
    }

    /**
     * @param array{
     *     id: int,
     *     title: string,
     *     messageId: int,
     *     chatId: int,
     *     channel: string,
     *     sourceDate: int,
     *     created: int,
     *     chatTitle: string|null
     * } $row
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     messageId: int,
     *     chatId: int|null,
     *     chatTitle: string|null,
     *     channel: string,
     *     sourceDate: int,
     *     created: int
     * }
     */
    private function presentEntry(array $row): array
    {
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'messageId' => $row['messageId'],
            'chatId' => self::chatIdOrNull($row['chatId']),
            'chatTitle' => self::publicChatTitle($row['chatTitle']),
            'channel' => $row['channel'],
            'sourceDate' => $row['sourceDate'],
            'created' => $row['created'],
        ];
    }

    private function userId(User $user): int
    {
        $userId = $user->getId();
        if (null === $userId) {
            throw new \LogicException('Authenticated user has no id.');
        }

        return $userId;
    }

    private static function chatIdOrNull(int $chatId): ?int
    {
        return $chatId > 0 ? $chatId : null;
    }

    private static function publicChatTitle(?string $title): ?string
    {
        if (null === $title || '' === $title || ChatDisplayTitle::isPlaceholder($title)) {
            return null;
        }

        return $title;
    }
}

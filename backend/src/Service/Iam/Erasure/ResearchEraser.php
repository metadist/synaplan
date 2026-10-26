<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Service\Research\Run\ResearchStateStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A deleted account takes its research runs with it. The rows of other
 * domains go first; the research keys go after the commit, because Redis
 * is not part of the database transaction.
 */
#[AsTaggedItem(priority: 10)]
final readonly class ResearchEraser implements UserDataEraserInterface
{
    public function __construct(
        private ResearchStateStore $store,
        private LoggerInterface $logger,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $erasure->afterCommit(function () use ($userId): void {
            try {
                $this->store->deleteUser($userId);
            } catch (\Throwable $e) {
                $this->logger->warning('Research runs were not erased with the account', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
            }
        });
    }

    public static function covers(): array
    {
        return [];
    }
}

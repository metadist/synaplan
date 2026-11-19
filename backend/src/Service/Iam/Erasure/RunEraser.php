<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Run;
use App\Repository\RunRepository;
use App\Service\Run\RunKeyRemoval;
use App\Service\Run\RunKeys;
use App\Service\Run\RunKeyspace;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A deleted account takes its runs with it, before its assistants, and its id
 * leaves the runs it started in other accounts. The rows go in the
 * transaction; the Redis keys go after the commit, because Redis is not part
 * of it.
 */
#[AsTaggedItem(priority: 155)]
final readonly class RunEraser implements UserDataEraserInterface
{
    public function __construct(
        private RunRepository $runs,
        private RunKeys $keys,
        private LoggerInterface $logger,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $keyspace = RunKeyspace::runs();
        $uuids = array_values(array_filter($this->runs->uuidsForUser($userId), $keyspace->isValid(...)));
        $this->runs->deleteForUser($userId);
        $this->runs->clearStartedBy($userId);
        if ([] === $uuids) {
            return;
        }
        $removal = new RunKeyRemoval($this->keys, $keyspace);
        $erasure->afterCommit(function () use ($removal, $uuids, $userId): void {
            foreach ($uuids as $uuid) {
                try {
                    $removal->delete($uuid);
                } catch (\Throwable $e) {
                    $this->logger->warning('A run was not erased from Redis with the account', [
                        'user_id' => $userId,
                        'exception' => $e,
                    ]);
                }
            }
        });
    }

    public static function covers(): array
    {
        return [Run::class];
    }
}

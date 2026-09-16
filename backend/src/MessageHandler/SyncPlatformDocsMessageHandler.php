<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncPlatformDocsMessage;
use App\Service\SelfAware\Docs\PlatformDocsSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPlatformDocsMessageHandler
{
    public function __construct(
        private PlatformDocsSyncService $syncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncPlatformDocsMessage $message): void
    {
        $result = $this->syncService->sync(force: $message->force);
        $this->logger->info('Platform docs sync finished', [
            'status' => $result->status,
            'changed' => $result->changed,
            'unchanged' => $result->unchanged,
            'removed' => $result->removed,
            'failed' => $result->failed,
            'reason' => $result->reason,
        ]);
    }
}

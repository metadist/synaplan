<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\PlatformLink\PickupService;

/**
 * Folder channels the honesty guard may name: WebDAV connections plus linked
 * clouds readable through the pickup protocol (#2667).
 */
final readonly class ConnectedFolderGuardCatalog
{
    public function __construct(
        private PlannerChannelCatalog $plannerChannels,
        private PickupService $pickups,
    ) {
    }

    /**
     * @return list<PlannerChannel>
     */
    public function folderSourcesForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $out = $this->plannerChannels->ofKind($userId, PlannerChannel::KIND_FOLDER);
        $used = array_map(static fn (PlannerChannel $channel): string => $channel->key, $out);
        foreach ($this->pickups->readableLinks($userId, $used) as $link) {
            $out[] = new PlannerChannel(
                $link['channel'],
                PlannerChannel::KIND_FOLDER,
                $link['host'],
                $link['identity_id'],
                ['folder_fetch'],
            );
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\Connection;
use App\Service\Connection\PlannerChannelCatalog;

/**
 * A WebDAV connection that is a Nextcloud or OpenCloud folder — the only
 * targets the Files "Send to cloud" action offers.
 */
final readonly class CloudFolderTarget
{
    public const NEXTCLOUD = 'nextcloud';
    public const OPENCLOUD = 'opencloud';

    /** @var list<string> */
    public const KINDS = [self::NEXTCLOUD, self::OPENCLOUD];

    /**
     * @return self::NEXTCLOUD|self::OPENCLOUD|null
     */
    public static function kindFor(Connection $connection): ?string
    {
        if ('webdav' !== $connection->getType()) {
            return null;
        }

        $config = $connection->getConfig() ?? [];
        $stored = is_string($config['channel'] ?? null)
            ? PlannerChannelCatalog::sanitize($config['channel'])
            : '';
        // A stored slug is authoritative: "folder" stays generic even if the
        // host happens to contain "opencloud". Planner unique() also persists
        // nextcloud-2 / opencloud-x when a second folder exists.
        if ('' !== $stored) {
            return self::kindFromChannel($stored);
        }

        $derived = PlannerChannelCatalog::preferredKey(
            $connection->getType(),
            $connection->getName(),
            $config,
        );

        return in_array($derived, self::KINDS, true) ? $derived : null;
    }

    /**
     * @return self::NEXTCLOUD|self::OPENCLOUD|null
     */
    public static function kindFromChannel(string $channel): ?string
    {
        if (1 !== preg_match('/^(nextcloud|opencloud)(?:-(?:\d+|x))?$/', $channel, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function isCloudFolder(Connection $connection): bool
    {
        return null !== self::kindFor($connection);
    }
}

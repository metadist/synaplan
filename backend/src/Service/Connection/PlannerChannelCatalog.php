<?php

declare(strict_types=1);

namespace App\Service\Connection;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;

/**
 * The single source of channel names the planner is allowed to use.
 *
 * Every connected folder, calendar or mailbox gets a stable slug (`nextcloud`,
 * `calendar`, `folder`). That slug is stored on the connection (`config.channel`)
 * and listed in the planner prompt as `[CHANNELLIST]`. Runners resolve
 * `params.channel` through this catalog — never by inventing an id.
 */
final readonly class PlannerChannelCatalog
{
    public function __construct(
        private ConnectionRepository $connections,
    ) {
    }

    /**
     * @return list<PlannerChannel>
     */
    public function forUser(int $ownerId): array
    {
        $used = [];
        $out = [];
        foreach ($this->connections->findByOwner($ownerId) as $connection) {
            $channel = $this->fromConnection($connection, $used);
            if (null === $channel) {
                continue;
            }
            $used[] = $channel->key;
            $out[] = $channel;
        }

        return $out;
    }

    public function find(int $ownerId, string $key): ?PlannerChannel
    {
        $needle = self::sanitize($key);
        if ('' === $needle) {
            return null;
        }
        foreach ($this->forUser($ownerId) as $channel) {
            if ($channel->key === $needle) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @return list<PlannerChannel>
     */
    public function ofKind(int $ownerId, string $kind): array
    {
        return array_values(array_filter(
            $this->forUser($ownerId),
            static fn (PlannerChannel $channel): bool => $channel->kind === $kind,
        ));
    }

    public function renderForPlanner(?int $ownerId): string
    {
        if (null === $ownerId || $ownerId <= 0) {
            return '(none)';
        }

        $channels = $this->forUser($ownerId);
        if ([] === $channels) {
            return '(none)';
        }

        $lines = [];
        foreach ($channels as $channel) {
            $lines[] = sprintf(
                '- "%s": %s — %s. Use params.channel="%s" with %s.',
                $channel->key,
                $channel->kind,
                $channel->label,
                $channel->key,
                implode(' / ', $channel->capabilities),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Pick a free slug for a new connection of this type.
     *
     * @param array<string, mixed> $config
     */
    public function suggest(int $ownerId, string $type, string $name, array $config = []): string
    {
        $used = [];
        foreach ($this->forUser($ownerId) as $channel) {
            $used[] = $channel->key;
        }

        return self::unique(self::preferredKey($type, $name, $config), $used);
    }

    /**
     * @param list<string> $usedKeys
     */
    public function fromConnection(Connection $connection, array $usedKeys = []): ?PlannerChannel
    {
        $id = $connection->getId();
        if (null === $id) {
            return null;
        }

        $kind = self::kindForType($connection->getType());
        if (null === $kind) {
            return null;
        }

        $config = $connection->getConfig() ?? [];
        $stored = is_string($config['channel'] ?? null) ? self::sanitize($config['channel']) : '';
        $key = '' !== $stored
            ? self::unique($stored, $usedKeys)
            : self::unique(self::preferredKey($connection->getType(), $connection->getName(), $config), $usedKeys);
        if ('' === $stored) {
            $this->persistKey($connection, $key);
        }

        return new PlannerChannel(
            $key,
            $kind,
            $connection->getName(),
            $id,
            self::capabilitiesForKind($kind),
        );
    }

    public static function sanitize(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = (string) preg_replace('/[^a-z0-9-]+/', '-', $slug);
        $slug = trim($slug, '-');

        return strlen($slug) > 32 ? substr($slug, 0, 32) : $slug;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function preferredKey(string $type, string $name, array $config = []): string
    {
        $haystack = strtolower($name.' '.(is_string($config['base_url'] ?? null) ? $config['base_url'] : ''));

        return match ($type) {
            'webdav' => str_contains($haystack, 'nextcloud') || str_contains($haystack, 'owncloud')
                ? 'nextcloud'
                : 'folder',
            'caldav' => 'calendar',
            Connection::TYPE_M365 => 'm365',
            'mailbox' => 'mailbox',
            default => self::sanitize($type) ?: 'channel',
        };
    }

    public static function kindForType(string $type): ?string
    {
        return match ($type) {
            'webdav' => PlannerChannel::KIND_FOLDER,
            'caldav' => PlannerChannel::KIND_CALENDAR,
            Connection::TYPE_M365, 'mailbox' => PlannerChannel::KIND_MAIL,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesForKind(string $kind): array
    {
        return match ($kind) {
            PlannerChannel::KIND_FOLDER => ['save_to_folder'],
            PlannerChannel::KIND_CALENDAR => ['calendar_event'],
            PlannerChannel::KIND_MAIL => ['email_search', 'email_me'],
            default => [],
        };
    }

    /**
     * @param list<string> $used
     */
    public static function unique(string $preferred, array $used): string
    {
        $base = '' !== $preferred ? $preferred : 'channel';
        if (!in_array($base, $used, true)) {
            return $base;
        }
        for ($i = 2; $i < 100; ++$i) {
            $candidate = $base.'-'.$i;
            if (!in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return $base.'-x';
    }

    private function persistKey(Connection $connection, string $key): void
    {
        $config = $connection->getConfig() ?? [];
        $config['channel'] = $key;
        $connection->setConfig($config);
        $this->connections->save($connection);
    }
}

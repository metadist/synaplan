<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use Psr\Log\LoggerInterface;

/**
 * Puts generated files into a user's connected folder — WebDAV/Nextcloud or
 * Dropbox, whichever the resolved connection is.
 *
 * Used by the `save_to_folder` multitask runner and as a chat fallback when
 * the user asked to file the result but the planner did not emit that node.
 */
final readonly class RequestedFolderDelivery
{
    /**
     * Connection types that count as a folder target. The type doubles as the
     * {@see DestinationProvider} id, so adding a type here requires a provider
     * with the same id.
     */
    private const FOLDER_TYPES = ['webdav', Connection::TYPE_DROPBOX];

    public function __construct(
        private ConnectionRepository $connections,
        private DestinationRegistry $destinations,
        private LoggerInterface $logger,
        private ?PlannerChannelCatalog $channels = null,
    ) {
    }

    /**
     * True when the user explicitly asked to put a result in Nextcloud / a
     * connected folder — not when they merely mentioned a cloud in passing.
     */
    public function userAskedToSaveToFolder(string $text): bool
    {
        $haystack = mb_strtolower($text);
        $mentionsFolder = str_contains($haystack, 'nextcloud')
            || str_contains($haystack, 'owncloud')
            || str_contains($haystack, 'webdav')
            || str_contains($haystack, 'opencloud')
            || str_contains($haystack, 'dropbox')
            || (bool) preg_match('/\b(folder|ordner|carpeta|klasör)\b/u', $haystack);

        if (!$mentionsFolder) {
            return false;
        }

        return (bool) preg_match(
            '/\b(save|put|file|store|upload|lege|speicher|ablage|guarda|kaydet)\b/u',
            $haystack
        );
    }

    /**
     * True when this user has at least one folder channel the planner may name.
     */
    public function hasFolderChannel(int $ownerId): bool
    {
        return [] !== $this->folderConnections($ownerId);
    }

    /**
     * @param list<array{path: string, name?: string}> $files absolute paths
     *
     * @return array{ok: bool, message: string, sent: int, connection: string|null, channel: string|null}
     */
    public function send(int $ownerId, array $files, string|int|null $channel = null): array
    {
        $connection = $this->resolveConnection($ownerId, $channel);
        if (null === $connection) {
            return [
                'ok' => false,
                'message' => 'no folder is connected — add one under Settings → Connections',
                'sent' => 0,
                'connection' => null,
                'channel' => null,
            ];
        }

        $connectionPk = $connection->getId();
        if (null === $connectionPk) {
            return [
                'ok' => false,
                'message' => 'no folder is connected — add one under Settings → Connections',
                'sent' => 0,
                'connection' => null,
                'channel' => null,
            ];
        }

        if ([] === $files) {
            return [
                'ok' => false,
                'message' => 'nothing to save: the previous steps produced no file',
                'sent' => 0,
                'connection' => $connection->getName(),
                'channel' => $this->channelKey($connection),
            ];
        }

        // The connection type doubles as the destination provider id
        // (webdav → WebDavDestinationProvider, dropbox → DropboxDestinationProvider).
        $provider = $this->destinations->get($connection->getType());
        $sent = 0;
        $lastReference = null;
        $lastError = null;

        foreach ($files as $index => $file) {
            $absolute = $file['path'];
            if (!is_file($absolute)) {
                continue;
            }
            $name = $file['name'] ?? basename($absolute);
            $result = $provider->send(
                new ShareableFile(
                    fileId: $index + 1,
                    ownerId: $ownerId,
                    absolutePath: $absolute,
                    name: $name,
                    sizeBytes: filesize($absolute) ?: 0,
                ),
                ['connection_id' => $connectionPk],
            );
            if ($result->ok) {
                ++$sent;
                $lastReference = $result->reference;
            } else {
                $lastError = $result->code->value;
                $this->logger->warning('RequestedFolderDelivery: upload failed', [
                    'connection_id' => $connectionPk,
                    'file' => $name,
                    'code' => $lastError,
                ]);
            }
        }

        if (0 === $sent) {
            return [
                'ok' => false,
                'message' => 'could not save the file to '.$connection->getName()
                    .(null !== $lastError ? ' ('.$lastError.')' : ''),
                'sent' => 0,
                'connection' => $connection->getName(),
                'channel' => $this->channelKey($connection),
            ];
        }

        $where = $this->channelKey($connection);
        if (is_string($lastReference) && '' !== $lastReference) {
            $where .= ' ('.$lastReference.')';
        }

        return [
            'ok' => true,
            'message' => 1 === $sent
                ? 'Saved the file to '.$where.'.'
                : sprintf('Saved %d files to %s.', $sent, $where),
            'sent' => $sent,
            'connection' => $connection->getName(),
            'channel' => $this->channelKey($connection),
        ];
    }

    /**
     * @return list<Connection>
     */
    public function folderConnections(int $ownerId): array
    {
        $out = [];
        foreach ($this->connections->findByOwner($ownerId) as $connection) {
            if (in_array($connection->getType(), self::FOLDER_TYPES, true)) {
                $out[] = $connection;
            }
        }

        return $out;
    }

    private function resolveConnection(int $ownerId, string|int|null $channel): ?Connection
    {
        $folders = $this->folderConnections($ownerId);
        if ([] === $folders) {
            return null;
        }

        if (is_int($channel) && $channel > 0) {
            foreach ($folders as $connection) {
                if ($connection->getId() === $channel) {
                    return $connection;
                }
            }

            return null;
        }

        $key = is_string($channel) ? PlannerChannelCatalog::sanitize($channel) : '';
        if ('' !== $key && null !== $this->channels) {
            $named = $this->channels->find($ownerId, $key);
            if (null !== $named && PlannerChannel::KIND_FOLDER === $named->kind) {
                foreach ($folders as $connection) {
                    if ($connection->getId() === $named->connectionId) {
                        return $connection;
                    }
                }
            }
            if (is_numeric($key)) {
                return $this->resolveConnection($ownerId, (int) $key);
            }

            return null;
        }

        if (1 === count($folders)) {
            return $folders[0];
        }

        foreach ($folders as $connection) {
            if ('nextcloud' === $this->channelKey($connection)) {
                return $connection;
            }
        }

        return $folders[0];
    }

    private function channelKey(Connection $connection): string
    {
        $config = $connection->getConfig() ?? [];
        $stored = is_string($config['channel'] ?? null) ? PlannerChannelCatalog::sanitize($config['channel']) : '';

        return '' !== $stored
            ? $stored
            : PlannerChannelCatalog::preferredKey($connection->getType(), $connection->getName(), $config);
    }
}

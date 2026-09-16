<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\DavConnectionResolver;
use App\Service\Dav\DavException;
use App\Service\Dav\DavTarget;
use App\Service\Dav\WebDavClient;

/**
 * "Save to folder" for any WebDAV server (connector plan 07 C10). Nextcloud
 * and ownCloud are presets of this provider, never bespoke integrations.
 *
 * Params: `connection_id` (a `webdav` connection owned by the file's owner).
 * Connection config: `base_url` (the DAV files collection), `username`,
 * optional `folder` (default "Synaplan") and `on_conflict`
 * (`rename` default / `overwrite`).
 */
final readonly class WebDavDestinationProvider implements DestinationProvider
{
    /** Per-run byte cap (connector sheet: enforce our own, no server limit documented). */
    private const MAX_BYTES = 100 * 1024 * 1024;

    private const DEFAULT_FOLDER = 'Synaplan';
    private const MAX_RENAME_ATTEMPTS = 20;

    public function __construct(
        private WebDavClient $webDav,
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
    ) {
    }

    public function id(): string
    {
        return 'webdav';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $connectionId = is_numeric($params['connection_id'] ?? null) ? (int) $params['connection_id'] : 0;
        $connection = $this->connections->findByIdAndOwner($connectionId, $file->ownerId);
        if (null === $connection || 'webdav' !== $connection->getType()) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => 'webdav']);
        }

        $target = DavConnectionResolver::resolve($connection, $this->vault);
        if (null === $target) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => $connection->getName()]);
        }

        if (!is_file($file->absolutePath)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }
        if ($file->sizeBytes > self::MAX_BYTES) {
            return DestinationResult::failure(DestinationFailureCode::TooLarge, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $config = $connection->getConfig() ?? [];
        $folder = is_string($config['folder'] ?? null) && '' !== trim($config['folder'], " /\t")
            ? trim($config['folder'], " /\t")
            : self::DEFAULT_FOLDER;
        $overwrite = 'overwrite' === ($config['on_conflict'] ?? 'rename');

        $content = file_get_contents($file->absolutePath);
        if (false === $content) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        try {
            $this->ensureFolder($target, $folder);
            $name = $this->targetName($target, $folder, $file->name, $overwrite);
            if (null === $name) {
                return DestinationResult::failure(DestinationFailureCode::Conflict, [
                    'target' => $file->name,
                    'connection' => $connection->getName(),
                ]);
            }
            $this->webDav->put($target, $folder.'/'.$name, $content, 'application/octet-stream');
        } catch (DavException $e) {
            return DestinationResult::failure($e->toFailureCode(), [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        return DestinationResult::success($folder.'/'.$name, [
            'connection' => $connection->getName(),
            'target' => $target->host(),
            'newName' => $name,
        ]);
    }

    /**
     * Create the folder hierarchy segment by segment; MKCOL treats an existing
     * collection as fine.
     *
     * @throws DavException
     */
    private function ensureFolder(DavTarget $target, string $folder): void
    {
        $current = '';
        foreach (explode('/', $folder) as $segment) {
            $current = '' === $current ? $segment : $current.'/'.$segment;
            if (!$this->webDav->exists($target, $current)) {
                $this->webDav->mkcol($target, $current);
            }
        }
    }

    /**
     * Pick the final remote file name: as-is, or "name (2).ext" style until a
     * free slot is found (`rename` conflict policy). Null when every candidate
     * is taken.
     *
     * @throws DavException
     */
    private function targetName(DavTarget $target, string $folder, string $name, bool $overwrite): ?string
    {
        $safe = str_replace(['/', '\\'], '-', $name);
        if ($overwrite || !$this->webDav->exists($target, $folder.'/'.$safe)) {
            return $safe;
        }

        $dot = strrpos($safe, '.');
        $stem = false === $dot ? $safe : substr($safe, 0, $dot);
        $extension = false === $dot ? '' : substr($safe, $dot);

        for ($i = 2; $i <= self::MAX_RENAME_ATTEMPTS; ++$i) {
            $candidate = sprintf('%s (%d)%s', $stem, $i, $extension);
            if (!$this->webDav->exists($target, $folder.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

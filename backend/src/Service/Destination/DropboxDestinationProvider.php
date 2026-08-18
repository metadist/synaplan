<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Dropbox\DropboxClient;
use App\Service\Dropbox\DropboxException;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthReauthRequiredException;

/**
 * "Save to folder" for a connected Dropbox account (connector plan 07 C13) —
 * the OAuth sibling of {@see WebDavDestinationProvider}.
 *
 * Params: `connection_id` (a `dropbox` connection owned by the file's owner).
 * Connection config: optional `folder` (default "Synaplan") and `on_conflict`
 * (`rename` default / `overwrite`). Rename conflicts are handled by Dropbox's
 * native `autorename`; folders are created implicitly by the upload.
 */
final readonly class DropboxDestinationProvider implements DestinationProvider
{
    private const DEFAULT_FOLDER = 'Synaplan';

    public function __construct(
        private DropboxClient $dropbox,
        private ConnectionRepository $connections,
    ) {
    }

    public function id(): string
    {
        return 'dropbox';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $connectionId = is_numeric($params['connection_id'] ?? null) ? (int) $params['connection_id'] : 0;
        $connection = $this->connections->findByIdAndOwner($connectionId, $file->ownerId);
        if (null === $connection || Connection::TYPE_DROPBOX !== $connection->getType()) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => 'dropbox']);
        }

        if (!is_file($file->absolutePath)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }
        if ($file->sizeBytes > DropboxClient::MAX_UPLOAD_BYTES) {
            return DestinationResult::failure(DestinationFailureCode::TooLarge, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $content = file_get_contents($file->absolutePath);
        if (false === $content) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $config = $connection->getConfig() ?? [];
        $folder = is_string($config['folder'] ?? null) && '' !== trim($config['folder'], " /\t")
            ? trim($config['folder'], " /\t")
            : self::DEFAULT_FOLDER;
        $overwrite = 'overwrite' === ($config['on_conflict'] ?? 'rename');
        $safeName = str_replace(['/', '\\'], '-', $file->name);

        try {
            $stored = $this->dropbox->upload($connection, $content, '/'.$folder.'/'.$safeName, $overwrite);
        } catch (OAuthReauthRequiredException|OAuthException) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, [
                'connection' => $connection->getName(),
            ]);
        } catch (DropboxException $e) {
            return DestinationResult::failure($this->failureCode($e), [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        return DestinationResult::success(ltrim($stored['path'], '/'), [
            'connection' => $connection->getName(),
            'target' => 'dropbox.com',
            'newName' => $stored['name'],
        ]);
    }

    /**
     * Map Dropbox `error_summary` tags onto the registry's failure codes so
     * the frontend renders the same readable message for every provider.
     */
    private function failureCode(DropboxException $e): DestinationFailureCode
    {
        $summary = $e->errorSummary;

        return match (true) {
            str_contains($summary, 'insufficient_space') => DestinationFailureCode::QuotaExceeded,
            str_contains($summary, 'too_large') => DestinationFailureCode::TooLarge,
            str_contains($summary, 'conflict') => DestinationFailureCode::Conflict,
            str_contains($summary, 'not_found') => DestinationFailureCode::NotFound,
            401 === $e->getCode(), 403 === $e->getCode() => DestinationFailureCode::Unauthorized,
            429 === $e->getCode() => DestinationFailureCode::RateLimited,
            default => DestinationFailureCode::Unreachable,
        };
    }
}

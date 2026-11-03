<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\Office\DocumentThumbnailGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckFileInfo, GetFile, PutFile and the lock verbs for one Synaplan file.
 *
 * A save does not append a document revision. The previous revision's binary
 * hash then no longer matches the bytes, so the next chat edit re-imports
 * the file instead of replaying the stale structured model.
 */
final readonly class WopiDocumentService
{
    public const CONFLICT_CODE = 1010;

    public function __construct(
        private FileRepository $files,
        private UserRepository $users,
        private WopiTokenService $tokens,
        private WopiDiscovery $discovery,
        private WopiLockStore $locks,
        private WopiFileUpdate $updates,
        private OfficeEditorConfig $config,
        private string $uploadDir,
    ) {
    }

    public function editSession(User $user, int $fileId): WopiHttpResult
    {
        if (!$this->config->isEnabled()) {
            return $this->error(Response::HTTP_NOT_FOUND, 'The office editor is not set up on this server.');
        }
        $userId = $user->getId();
        if (null === $userId) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'Not authenticated');
        }
        $file = $this->files->find($fileId);
        if (!$file instanceof File) {
            return $this->error(Response::HTTP_NOT_FOUND, 'File not found');
        }
        if ($file->getUserId() !== $userId) {
            return $this->error(Response::HTTP_FORBIDDEN, 'Only the owner can open this file in the editor.');
        }
        $absolute = $this->absolutePath($file);
        if (null === $absolute || !is_file($absolute)) {
            return $this->error(Response::HTTP_NOT_FOUND, 'File not found');
        }
        $extension = DocumentThumbnailGenerator::extensionOf($file);
        $map = $this->discovery->map();
        if (null === $map) {
            return $this->error(Response::HTTP_SERVICE_UNAVAILABLE, 'The office editor is not reachable. Try again in a moment.');
        }
        $action = $map[$extension] ?? null;
        if (null === $action) {
            return $this->error(Response::HTTP_UNPROCESSABLE_ENTITY, 'This file cannot be opened in the editor.');
        }
        $now = time();
        $wopiSrc = $this->config->wopiSrc($fileId);

        return new WopiHttpResult(Response::HTTP_OK, [
            'editorUrl' => $this->config->editorUrl($action['path'], $wopiSrc),
            'accessToken' => $this->tokens->issue($fileId, $userId, $now),
            'accessTokenTtl' => ($now + WopiTokenService::TTL_SECONDS) * 1000,
            'wopiSrc' => $wopiSrc,
        ]);
    }

    public function checkFileInfo(WopiAccess $access): WopiHttpResult
    {
        $loaded = $this->loadOwned($access);
        if ($loaded instanceof WopiHttpResult) {
            return $loaded;
        }
        [$file, $user] = $loaded;
        $extension = DocumentThumbnailGenerator::extensionOf($file);
        $action = $this->discovery->actionFor($extension);
        $canWrite = null === $action || $action['writable'];
        $name = basename($file->getFileName());
        if ('' === $name || '.' === $name || '..' === $name) {
            $name = 'document';
        }

        return new WopiHttpResult(Response::HTTP_OK, [
            'BaseFileName' => $name,
            'Size' => $file->getFileSize(),
            'OwnerId' => (string) $file->getUserId(),
            'UserId' => (string) $access->userId,
            'UserFriendlyName' => $user->getDisplayName(),
            'UserCanWrite' => $canWrite,
            'ReadOnly' => !$canWrite,
            'LastModifiedTime' => WopiTimestamp::format($file->getUpdatedAt()),
            'Version' => (string) $file->getUpdatedAt(),
            'PostMessageOrigin' => $this->config->postMessageOrigin(),
            'SupportsLocks' => true,
            'SupportsGetLock' => true,
            'SupportsUpdate' => true,
            'UserCanNotWriteRelative' => true,
            'EnableInsertRemoteImage' => true,
        ]);
    }

    public function getFile(WopiAccess $access): WopiHttpResult
    {
        $loaded = $this->loadOwned($access);
        if ($loaded instanceof WopiHttpResult) {
            return $loaded;
        }
        [$file] = $loaded;
        $absolute = $this->absolutePath($file);
        if (null === $absolute || !is_file($absolute)) {
            return $this->error(Response::HTTP_NOT_FOUND, 'File not found');
        }

        return new WopiHttpResult(
            Response::HTTP_OK,
            filePath: $absolute,
            downloadName: basename($file->getFileName()) ?: 'document',
        );
    }

    public function putFile(WopiAccess $access, string $bytes, string $timestamp, string $lock): WopiHttpResult
    {
        return $this->locks->exclusive($access->fileId, function () use ($access, $bytes, $timestamp, $lock): WopiHttpResult {
            $loaded = $this->loadOwned($access);
            if ($loaded instanceof WopiHttpResult) {
                return $loaded;
            }
            [$file, $user] = $loaded;
            if (!$this->updates->refresh($file)) {
                return $this->error(Response::HTTP_NOT_FOUND, 'File not found');
            }
            $fileId = $file->getId() ?? $access->fileId;
            $held = $this->locks->current($fileId);
            $requested = trim($lock);
            if ('' === $held) {
                // An unlocked file accepts PutFile only while it is still empty.
                if ('' !== $requested || $file->getFileSize() > 0) {
                    return new WopiHttpResult(Response::HTTP_CONFLICT, headers: ['X-WOPI-Lock' => '']);
                }
            } elseif (!hash_equals($held, $requested)) {
                return new WopiHttpResult(Response::HTTP_CONFLICT, headers: ['X-WOPI-Lock' => $held]);
            }
            if ('' !== trim($timestamp) && !WopiTimestamp::matches($timestamp, $file->getUpdatedAt())) {
                return new WopiHttpResult(Response::HTTP_CONFLICT, ['COOLStatusCode' => self::CONFLICT_CODE]);
            }
            $rejection = $this->updates->rejection($user, strlen($bytes), $file->getFileSize());
            if ($rejection instanceof WopiHttpResult) {
                return $rejection;
            }
            $failed = $this->updates->store($file, $bytes, $access->fileId, $access->userId);
            if ($failed instanceof WopiHttpResult) {
                return $failed;
            }

            return new WopiHttpResult(Response::HTTP_OK, [
                'LastModifiedTime' => WopiTimestamp::format($file->getUpdatedAt()),
            ]);
        });
    }

    public function lock(WopiAccess $access, string $override, string $lockId, string $oldLock): WopiHttpResult
    {
        $loaded = $this->loadOwned($access);
        if ($loaded instanceof WopiHttpResult) {
            return $loaded;
        }
        $fileId = $access->fileId;
        $verb = strtoupper(trim($override));
        $result = match ($verb) {
            'LOCK' => '' !== trim($oldLock)
                ? $this->locks->relock($fileId, $oldLock, $lockId)
                : $this->locks->lock($fileId, $lockId),
            'UNLOCK' => $this->locks->unlock($fileId, $lockId),
            'REFRESH_LOCK' => $this->locks->refresh($fileId, $lockId),
            'UNLOCK_AND_RELOCK' => $this->locks->relock($fileId, $oldLock, $lockId),
            'GET_LOCK' => ['ok' => true, 'current' => $this->locks->current($fileId)],
            default => null,
        };
        if (null === $result) {
            return $this->error(Response::HTTP_NOT_IMPLEMENTED, 'That WOPI operation is not supported.');
        }
        if (!$result['ok']) {
            return new WopiHttpResult(Response::HTTP_CONFLICT, headers: ['X-WOPI-Lock' => $result['current']]);
        }

        return new WopiHttpResult(Response::HTTP_OK, headers: ['X-WOPI-Lock' => $result['current']]);
    }

    /**
     * @return array{0: File, 1: User}|WopiHttpResult
     */
    private function loadOwned(WopiAccess $access): array|WopiHttpResult
    {
        if (!$this->config->isEnabled()) {
            return $this->error(Response::HTTP_NOT_FOUND, 'The office editor is not set up on this server.');
        }
        $file = $this->files->find($access->fileId);
        if (!$file instanceof File || $file->getUserId() !== $access->userId) {
            return $this->error(Response::HTTP_NOT_FOUND, 'File not found');
        }
        $user = $this->users->find($access->userId);
        if (!$user instanceof User) {
            return $this->error(Response::HTTP_UNAUTHORIZED, 'Not authenticated');
        }

        return [$file, $user];
    }

    private function absolutePath(File $file): ?string
    {
        $relative = $file->getFilePath();
        if ('' === $relative) {
            return null;
        }

        return $this->uploadDir.'/'.ltrim($relative, '/');
    }

    private function error(int $status, string $message): WopiHttpResult
    {
        return new WopiHttpResult($status, ['error' => $message]);
    }
}

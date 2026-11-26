<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;

/**
 * Paths and copies for media a third party (Meta WhatsApp) fetches without
 * a credential. Which names are served that way is decided by
 * {@see AnonymousMediaGate}; the names come from {@see PublicMediaName}.
 */
final class OutboundChannelMedia
{
    /**
     * Strip the public serve prefix so a DAG `/api/v1/files/uploads/…` path
     * and a raw upload-dir-relative path both resolve to the same relative key.
     */
    public static function relativeUploadPath(string $mediaPath): string
    {
        $prefix = '/api/v1/files/uploads/';
        if (str_starts_with($mediaPath, $prefix)) {
            $mediaPath = substr($mediaPath, strlen($prefix));
        }

        return ltrim($mediaPath, '/');
    }

    /**
     * Copy a file to an unguessable, anonymously fetchable name so Meta can
     * retrieve the WhatsApp `link` without credentials.
     *
     * @return string|null upload-dir-relative path of the published copy
     */
    public static function publishCopy(
        string $absoluteSource,
        string $uploadDir,
        int $userId,
        string $kind,
        UserUploadPathBuilder $pathBuilder,
    ): ?string {
        if (!is_file($absoluteSource)) {
            return null;
        }

        $extension = strtolower(pathinfo($absoluteSource, PATHINFO_EXTENSION));
        if ('' === $extension || 1 !== preg_match('/^[a-z0-9]+$/', $extension)) {
            $extension = 'bin';
        }

        $filename = PublicMediaName::generated($userId, $kind, $extension);

        $userBase = $pathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$filename;
        $absoluteTarget = rtrim($uploadDir, '/').'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            return null;
        }

        if (!@copy($absoluteSource, $absoluteTarget)) {
            return null;
        }

        FileHelper::setFilePermissions($absoluteTarget);

        return $relativePath;
    }
}

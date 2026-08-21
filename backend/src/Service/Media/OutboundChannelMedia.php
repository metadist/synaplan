<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;

/**
 * Filenames third parties (Meta WhatsApp, i2v providers) may fetch without auth.
 *
 * Must stay in lockstep with {@see \App\Controller\StaticUploadController}'s
 * anonymous-serve gate. Do not add patterns here unless that controller
 * (and its tests) gain the same bypass.
 */
final class OutboundChannelMedia
{
    /**
     * AI-generated images/videos/audio: `{messageId}_{provider}_{timestamp}.{ext}`.
     * WhatsApp document copies published by {@see self::publishCopy()}:
     * `{userId}_{kind}_{timestamp}{nonce}.{ext}`.
     */
    public const OUTBOUND_FILENAME_PATTERN = '/^\d+_[a-z]+_\d+\.[a-z0-9]+$/i';

    public static function isOutboundChannelMedia(string $filename): bool
    {
        return str_starts_with($filename, 'tts_')
            || 1 === preg_match(self::OUTBOUND_FILENAME_PATTERN, $filename);
    }

    public static function isRemoteFetchedSource(string $filename): bool
    {
        return str_starts_with($filename, 'ai_i2vsrc_');
    }

    public static function isAnonymouslyFetchable(string $filename): bool
    {
        return self::isRemoteFetchedSource($filename)
            || self::isOutboundChannelMedia($filename);
    }

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
     * Copy a generated file to an anonymously-fetchable name so Meta can
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

        $sanitizedKind = preg_replace('/[^a-z]/', '', strtolower($kind));
        $kind = (is_string($sanitizedKind) && '' !== $sanitizedKind) ? $sanitizedKind : 'file';
        $filename = sprintf('%d_%s_%d%d.%s', $userId, $kind, time(), random_int(100, 999), $extension);

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

<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;

/**
 * The bird state for one cloud file (plan 04 §A.1). The version is compared
 * exactly as the cloud sent it, quotes included.
 */
final class CloudFileState
{
    public const SEARCHABLE = 'searchable';
    public const ADDING = 'adding';
    public const UPDATING = 'updating';
    public const CHANGED = 'changed';
    public const FAILED = 'failed';
    public const NONE = 'none';

    public static function of(?File $file, string $version): string
    {
        if (!$file instanceof File || null !== $file->getSourceDeletedAt()) {
            return self::NONE;
        }
        if ('error' === $file->getStatus() || File::VECTOR_STATE_FAILED === $file->getVectorState()) {
            return self::FAILED;
        }
        if (in_array($file->getStatus(), ['extracting', 'vectorizing', 'processing'], true) || File::VECTOR_STATE_PENDING === $file->getVectorState()) {
            return self::ADDING;
        }
        if ($file->isStale() || File::VECTOR_STATE_STALE === $file->getVectorState() || ($file->getSourceEtag() ?? '') !== $version) {
            return $file->isKeepSynced() ? self::UPDATING : self::CHANGED;
        }
        if (File::VECTOR_STATE_VECTORIZED === $file->getVectorState()) {
            return self::SEARCHABLE;
        }

        return self::NONE;
    }
}

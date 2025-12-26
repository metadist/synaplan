<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * Builds stable per-user upload directory paths.
 *
 * Base directory layout (under var/uploads):
 * - Level 1: last 2 digits of user id (padded to at least 5 digits)
 * - Level 2: the 3 digits preceding the last 2
 * - Level 3: the full (padded) user id
 *
 * Examples:
 * - 13      => 13/000/00013
 * - 809     => 09/008/00809
 * - 1234567 => 67/345/1234567
 */
final class UserUploadPathBuilder
{
    public function buildUserBaseRelativePath(int $userId): string
    {
        // Keep behavior stable even for small IDs by padding to 5 digits.
        $padded = str_pad((string) $userId, 5, '0', STR_PAD_LEFT);

        $level1 = substr($padded, -2);
        $level2 = substr($padded, -5, 3);

        return $level1.'/'.$level2.'/'.$padded;
    }
}

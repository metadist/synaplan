<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Names the place a stored secret belongs to: the table, the field and the
 * owner. A ciphertext is bound to this name when it is written, so a value
 * copied into another owner's row or into another field does not open there.
 */
final class SecretPlace
{
    private const CONFIG_TABLE = 'BCONFIG';

    /**
     * A BCONFIG row. Group and setting are matched without regard to case,
     * like the database matches them.
     */
    public static function config(int $ownerId, string $group, string $setting): string
    {
        return self::name(self::CONFIG_TABLE, strtolower($group).'/'.strtolower($setting), $ownerId);
    }

    /**
     * A field of an entity row that belongs to one owner.
     */
    public static function column(string $table, string $field, int $ownerId): string
    {
        return self::name($table, $field, $ownerId);
    }

    private static function name(string $table, string $field, int $ownerId): string
    {
        return json_encode([$table, $field, $ownerId], \JSON_THROW_ON_ERROR);
    }
}

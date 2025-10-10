<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Custom DBAL Type für MariaDB 11.7+ VECTOR Typ
 * 
 * Mappt VECTOR(dimensions) zu TEXT/JSON für Doctrine
 */
class VectorType extends Type
{
    public const NAME = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // In der DB als VECTOR speichern, wenn MariaDB 11.7+
        // Ansonsten als TEXT
        $dimensions = $column['length'] ?? 1024;
        return "VECTOR({$dimensions})";
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        // MariaDB VECTOR wird als JSON Array zurückgegeben
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_string($value)) {
            // Ensure it's valid JSON
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        return null;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}


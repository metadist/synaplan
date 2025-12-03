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

        // MariaDB VECTOR Type Best Practice:
        // 1. Use VEC_FromText() for inserts
        // 2. Format: [1.0,2.0,3.0] (no spaces)
        // 3. Let Doctrine handle as binary data internally

        if (is_array($value)) {
            // Convert to MariaDB VECTOR format
            // Format each float with precision, no trailing zeros
            $formatted = array_map(function($v) {
                $float = floatval($v);
                // Use rtrim to remove trailing zeros and decimal point if needed
                return rtrim(rtrim(sprintf('%.8f', $float), '0'), '.');
            }, $value);
            
            return '[' . implode(',', $formatted) . ']';
        }

        if (is_string($value)) {
            // If already in correct format, return as-is
            if (preg_match('/^\[[\d.,\-e]+\]$/', $value)) {
                return $value;
            }
            
            // Try to parse as JSON and convert
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $formatted = array_map(function($v) {
                    $float = floatval($v);
                    return rtrim(rtrim(sprintf('%.8f', $float), '0'), '.');
                }, $decoded);
                return '[' . implode(',', $formatted) . ']';
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


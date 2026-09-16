<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * Office kinds the structured document model can represent.
 */
final class DocumentKind
{
    public const XLSX = 'xlsx';
    public const DOCX = 'docx';
    public const PPTX = 'pptx';

    public const ALL = [self::XLSX, self::DOCX, self::PPTX];

    private function __construct()
    {
    }

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::ALL, true);
    }

    public static function fromExtension(string $extension): ?string
    {
        $ext = strtolower($extension);
        if (in_array($ext, ['xls', 'xlsx', 'ods', 'csv', 'numbers'], true)) {
            return self::XLSX;
        }
        if (in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'pages'], true)) {
            return self::DOCX;
        }
        if (in_array($ext, ['ppt', 'pptx', 'odp', 'key'], true)) {
            return self::PPTX;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * How many files a watch may take, and the sentences the dialog shows at the cap.
 */
final class WatchLimits
{
    public const TASK_PER_HOUR = 100;
    public const SEARCHABLE_PER_HOUR = 500;
    public const INSTANCE_PER_HOUR = 1000;
    public const MAX_BYTES = 104857600;
    public const DEFAULT_PER_PERSON = 20;
    public const DEFAULT_PER_INSTANCE = 1000;

    /** @var list<string> */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'txt', 'md', 'markdown', 'ppt', 'pptx', 'odp', 'xls', 'xlsx', 'ods', 'csv', 'rtf',
    ];

    public function perHour(bool $searchable): int
    {
        return $searchable ? self::SEARCHABLE_PER_HOUR : self::TASK_PER_HOUR;
    }

    public function readable(string $filename, bool $images): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
            return true;
        }

        return $images && in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    public function personSentence(int $cap): string
    {
        return 'You already watch '.$this->number($cap).' folders and files with Synaplan, the most allowed. Stop one in Tasks → Watched folders to start another.';
    }

    public function instanceSentence(string $host, int $cap): string
    {
        return $host.' already has '.$this->number($cap).' watched folders and files, the most Synaplan allows. Ask your administrator.';
    }

    private function number(int $cap): string
    {
        return number_format($cap, 0, '.', ' ');
    }
}

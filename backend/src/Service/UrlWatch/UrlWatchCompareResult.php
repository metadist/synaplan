<?php

declare(strict_types=1);

namespace App\Service\UrlWatch;

use App\Entity\UrlWatch;

final readonly class UrlWatchCompareResult
{
    public const FIRST = 'first_save';
    public const UNCHANGED = 'unchanged';
    public const CHANGED = 'changed';

    public function __construct(
        public string $status,
        public UrlWatch $watch,
        public string $diffText,
        public string $promptText,
    ) {
    }
}

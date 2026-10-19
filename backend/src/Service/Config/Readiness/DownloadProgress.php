<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

/**
 * Progress of a model download, as far as the service reports it.
 */
final readonly class DownloadProgress
{
    public function __construct(
        public ?int $percent,
        public ?string $model,
        public ?int $minutesLeft,
    ) {
    }
}

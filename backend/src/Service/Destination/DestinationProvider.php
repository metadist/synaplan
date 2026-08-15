<?php

declare(strict_types=1);

namespace App\Service\Destination;

interface DestinationProvider
{
    public function id(): string;

    /**
     * @param array<string, mixed> $params
     */
    public function send(ShareableFile $file, array $params): DestinationResult;
}

<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Service\PlatformLink\CloudDeliveryService;

/**
 * Copy a file into a linked cloud. The cloud fetches the bytes itself;
 * this returns as soon as the copy is confirmed or the wait is over.
 */
final readonly class PlatformDestinationProvider implements DestinationProvider
{
    private const MAX_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private CloudDeliveryService $deliveries,
    ) {
    }

    public function id(): string
    {
        return 'platform';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        if ($file->sizeBytes > self::MAX_BYTES) {
            return DestinationResult::failure(DestinationFailureCode::TooLarge);
        }

        return $this->deliveries->copy($file, $params);
    }
}

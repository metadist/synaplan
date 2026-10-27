<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Repository\CloudDeliveryRepository;

/**
 * Stops a watch from running on a file Synaplan itself just wrote.
 *
 * The app ignores its own writes for 10 minutes. Synaplan also drops a remote
 * id that is in the copy ledger, and a Translate watch skips a name that
 * already carries its language suffix.
 */
final readonly class LoopGuard
{
    public const OWN_WRITE_SECONDS = 600;

    public function __construct(private CloudDeliveryRepository $deliveries)
    {
    }

    public function reason(CloudWatch $watch, string $remoteId, string $filename, bool $declaredOwnWrite, int $now): ?string
    {
        if ($declaredOwnWrite) {
            return 'own_write';
        }
        $identityId = $watch->getIdentityId();
        if (null !== $identityId && $this->deliveries->writtenRecently($identityId, $remoteId, $now - self::OWN_WRITE_SECONDS)) {
            return 'own_write';
        }
        $language = $watch->getFilter()['language'] ?? null;
        if (CloudWatch::PRESET_TRANSLATE === $watch->getPreset() && is_string($language) && '' !== $language) {
            if (1 === preg_match('/\.'.preg_quote($language, '/').'\./', $filename)) {
                return 'language_suffix';
            }
        }

        return null;
    }
}

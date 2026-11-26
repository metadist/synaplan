<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\ConfigRepository;

/**
 * Decides whether an upload may be served without a credential, by its name.
 *
 * - A name with 128 random bits ({@see PublicMediaName}) is always served.
 * - An old-style name is served only when the time in the name lies before
 *   the moment this instance was upgraded. Links handed out earlier keep
 *   working; a file created afterwards is never public just because its name
 *   happens to look like an old one.
 *
 * The moment is the `MEDIA / LEGACY_PUBLIC_NAMES_UNTIL` row a migration
 * writes once. Without a readable row no old-style name is served.
 */
final readonly class AnonymousMediaGate
{
    public const CONFIG_GROUP = 'MEDIA';
    public const CONFIG_SETTING = 'LEGACY_PUBLIC_NAMES_UNTIL';

    private const GLOBAL_OWNER = 0;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function allows(string $filename): bool
    {
        if (PublicMediaName::isUnguessable($filename)) {
            return true;
        }

        $createdAt = PublicMediaName::legacyCreatedAt($filename);
        if (null === $createdAt) {
            return false;
        }

        $until = $this->legacyNamesUntil();

        return null !== $until && $createdAt < $until;
    }

    private function legacyNamesUntil(): ?int
    {
        $value = $this->configRepository->getValue(self::GLOBAL_OWNER, self::CONFIG_GROUP, self::CONFIG_SETTING);
        if (null === $value || 1 !== preg_match('/^\d{1,12}$/', trim($value))) {
            return null;
        }

        return (int) trim($value);
    }
}

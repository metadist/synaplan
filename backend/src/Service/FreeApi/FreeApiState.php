<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\Repository\ConfigRepository;
use App\Service\Security\EncryptionService;
use Symfony\Component\Uid\Uuid;

/**
 * Local state of the Synaplan Demo, stored in BCONFIG (owner 0, group FREEAPI).
 *
 * The install key itself lives encrypted in the endpoint registry row. Only a
 * key whose revoke could not reach the server is kept here, encrypted with
 * APP_SECRET, until the scheduler delivers the revoke.
 */
final readonly class FreeApiState
{
    private const INSTALL_ID = 'INSTALL_ID';
    private const ACTIVE = 'ACTIVE';
    private const EXPIRES_AT = 'EXPIRES_AT';
    private const KEY_ID = 'KEY_ID';
    private const LIMITS = 'LIMITS';
    private const SNAPSHOT = 'SNAPSHOT';
    private const PENDING_REVOKE = 'PENDING_REVOKE';
    private const PENDING_REVOKE_UNTIL = 'PENDING_REVOKE_UNTIL';
    private const ACTIVATED_AT = 'ACTIVATED_AT';

    public function __construct(
        private ConfigRepository $configRepository,
        private EncryptionService $encryption,
    ) {
    }

    /** The install id, created on first use. Kept across leave so the server sees one install, not a new one per activation. */
    public function installId(): string
    {
        $stored = $this->read(self::INSTALL_ID);
        if (null !== $stored && Uuid::isValid($stored)) {
            return strtolower($stored);
        }

        $installId = Uuid::v4()->toRfc4122();
        $this->write(self::INSTALL_ID, $installId);

        return $installId;
    }

    public function existingInstallId(): ?string
    {
        $stored = $this->read(self::INSTALL_ID);

        return null !== $stored && Uuid::isValid($stored) ? strtolower($stored) : null;
    }

    public function isActive(): bool
    {
        return '1' === $this->read(self::ACTIVE);
    }

    /**
     * @param array<string, int|float> $limits
     */
    public function markActive(int $keyId, int $expiresAt, array $limits, int $now): void
    {
        $this->write(self::KEY_ID, (string) $keyId);
        $this->write(self::EXPIRES_AT, (string) $expiresAt);
        $this->write(self::LIMITS, json_encode($limits, \JSON_THROW_ON_ERROR));
        $this->write(self::ACTIVATED_AT, (string) $now);
        $this->write(self::ACTIVE, '1');
    }

    public function markInactive(): void
    {
        foreach ([self::ACTIVE, self::KEY_ID, self::EXPIRES_AT, self::LIMITS, self::ACTIVATED_AT] as $setting) {
            $this->configRepository->deleteValue(0, FreeApiConfig::GROUP, $setting);
        }
    }

    public function expiresAt(): ?int
    {
        $stored = $this->read(self::EXPIRES_AT);

        return null !== $stored && ctype_digit($stored) ? (int) $stored : null;
    }

    public function activatedAt(): ?int
    {
        $stored = $this->read(self::ACTIVATED_AT);

        return null !== $stored && ctype_digit($stored) ? (int) $stored : null;
    }

    /**
     * @return array<string, int|float>
     */
    public function limits(): array
    {
        $decoded = json_decode($this->read(self::LIMITS) ?? '', true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, static fn (mixed $value): bool => \is_int($value) || \is_float($value));
    }

    /**
     * @param array<string, string|null> $bindings capability => DEFAULTMODEL value before activation (null: no row)
     */
    public function saveSnapshot(array $bindings): void
    {
        $this->write(self::SNAPSHOT, json_encode($bindings, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string|null>|null null when no snapshot is stored
     */
    public function snapshot(): ?array
    {
        $decoded = json_decode($this->read(self::SNAPSHOT) ?? '', true);
        if (!\is_array($decoded)) {
            return null;
        }

        $bindings = [];
        foreach ($decoded as $capability => $value) {
            if (\is_string($capability) && (null === $value || \is_string($value))) {
                $bindings[$capability] = $value;
            }
        }

        return $bindings;
    }

    public function clearSnapshot(): void
    {
        $this->configRepository->deleteValue(0, FreeApiConfig::GROUP, self::SNAPSHOT);
    }

    /**
     * @param int|null $until when the key expires on the server anyway; past it there is nothing left to revoke
     */
    public function rememberPendingRevoke(string $key, ?int $until): void
    {
        $this->write(self::PENDING_REVOKE, $this->encryption->encrypt($key));
        if (null === $until) {
            $this->configRepository->deleteValue(0, FreeApiConfig::GROUP, self::PENDING_REVOKE_UNTIL);
        } else {
            $this->write(self::PENDING_REVOKE_UNTIL, (string) $until);
        }
    }

    public function pendingRevokeUntil(): ?int
    {
        $stored = $this->read(self::PENDING_REVOKE_UNTIL);

        return null !== $stored && ctype_digit($stored) ? (int) $stored : null;
    }

    public function pendingRevoke(): ?string
    {
        $stored = $this->read(self::PENDING_REVOKE);
        if (null === $stored) {
            return null;
        }

        try {
            $key = $this->encryption->decrypt($stored);
        } catch (\Throwable) {
            return null;
        }

        return '' !== $key ? $key : null;
    }

    public function clearPendingRevoke(): void
    {
        $this->configRepository->deleteValue(0, FreeApiConfig::GROUP, self::PENDING_REVOKE);
        $this->configRepository->deleteValue(0, FreeApiConfig::GROUP, self::PENDING_REVOKE_UNTIL);
    }

    private function read(string $setting): ?string
    {
        $value = $this->configRepository->getValue(0, FreeApiConfig::GROUP, $setting);

        return null !== $value && '' !== trim($value) ? trim($value) : null;
    }

    private function write(string $setting, string $value): void
    {
        $this->configRepository->setValue(0, FreeApiConfig::GROUP, $setting, $value);
    }
}

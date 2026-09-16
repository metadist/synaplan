<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\ApiKey;
use App\Entity\DesktopDevice;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\UserRepository;
use App\Security\ApiKeyScope;
use App\Service\Desktop\Exception\PairingException;

/**
 * Mints (and revokes) the scoped API key that binds a paired computer to a
 * Synaplan account.
 *
 * The key is created with exactly {@see ApiKeyScope::pairingScopes()} — narrow
 * by construction — and returned once. After that it lives only in the client's
 * OS secret store; the server never shows it again.
 */
final readonly class PairingService
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private DesktopDeviceRepository $deviceRepository,
        private UserRepository $userRepository,
        private string $appUrl,
    ) {
    }

    /**
     * Exchange a validated pairing (userId already resolved from the code) for a
     * scoped key and a device row.
     *
     * @param array<int|string, mixed> $capabilities capabilities the device declares (v1: `skill.run`); untrusted JSON, sanitized here
     *
     * @return array{deviceId: int, key: string, apiBaseUrl: string}
     *
     * @throws PairingException when the owning user no longer exists
     */
    public function pair(int $userId, string $deviceName, array $capabilities): array
    {
        // BAPIKEYS.BOWNERID is the join column of the owner relation, so the key
        // must be bound via setOwner() (a bare setOwnerId() writes NULL).
        $owner = $this->userRepository->find($userId);
        if (!$owner instanceof User) {
            throw new PairingException(\sprintf('Cannot pair: user %d no longer exists.', $userId));
        }

        $name = self::sanitizeDeviceName($deviceName);

        // sk_ (3) + 58 hex chars = 61 chars (fits VARCHAR(64)) — same shape as
        // the manually-created keys in ApiKeyController.
        $keyValue = 'sk_'.bin2hex(random_bytes(29));

        $apiKey = (new ApiKey())
            ->setOwner($owner)
            ->setKey($keyValue)
            ->setName('Desktop — '.$name)
            ->setStatus('active')
            ->setScopes(ApiKeyScope::pairingScopes());

        $this->apiKeyRepository->save($apiKey);

        $device = (new DesktopDevice())
            ->setOwnerId($userId)
            ->setName($name)
            ->setApiKeyId((int) $apiKey->getId())
            ->setStatus(DesktopDevice::STATUS_ACTIVE)
            ->setCapabilities(self::sanitizeCapabilities($capabilities));

        $this->deviceRepository->save($device);

        return [
            'deviceId' => (int) $device->getId(),
            'key' => $keyValue,
            'apiBaseUrl' => rtrim($this->appUrl, '/'),
        ];
    }

    /**
     * Revoke a device: delete its API key (so the next request 401s) and flag
     * the device row `revoked`. Idempotent — a missing key is fine.
     */
    public function revoke(DesktopDevice $device): void
    {
        $apiKey = $this->apiKeyRepository->find($device->getApiKeyId());
        if ($apiKey instanceof ApiKey) {
            $this->apiKeyRepository->remove($apiKey, false);
        }

        $device->setStatus(DesktopDevice::STATUS_REVOKED);
        $this->deviceRepository->save($device);
    }

    private static function sanitizeDeviceName(string $name): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ('' === $clean) {
            $clean = 'Computer';
        }

        return mb_substr($clean, 0, 128);
    }

    /**
     * @param array<int|string, mixed> $capabilities
     *
     * @return list<string>
     */
    private static function sanitizeCapabilities(array $capabilities): array
    {
        $out = [];
        foreach ($capabilities as $capability) {
            if (!\is_string($capability)) {
                continue;
            }
            $trimmed = trim($capability);
            if ('' !== $trimmed && preg_match('/^[a-z0-9._-]{1,64}$/', $trimmed)) {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }
}

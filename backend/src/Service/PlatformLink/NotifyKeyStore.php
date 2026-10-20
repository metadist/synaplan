<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Repository\ConfigRepository;
use App\Service\Federation\FederationSigner;
use App\Service\Infrastructure\RedisService;
use App\Service\Security\EncryptionService;

/**
 * The Ed25519 key Synaplan signs cloud notifications with. The public half
 * is listed to the app; the secret stays encrypted in BCONFIG.
 */
final class NotifyKeyStore
{
    public const GROUP = 'PLATFORM_LINKS';
    public const SETTING = 'NOTIFY_KEY';

    private const SIGN_DELAY = 3600;
    private const RETIRE_AFTER = 86400;

    public function __construct(
        private ConfigRepository $configs,
        private EncryptionService $encryption,
        private FederationSigner $signer,
        private RedisService $redis,
    ) {
    }

    /**
     * @return list<array{publicKey: string, kid: string}>
     */
    public function publicKeys(): array
    {
        $out = [];
        foreach ($this->ensure() as $key) {
            $out[] = [
                'publicKey' => $key['publicKey'],
                'kid' => $this->signer->fingerprint($key['publicKey']),
            ];
        }

        return $out;
    }

    /**
     * @return array{publicKey: string, secretKey: string}
     */
    public function signingKey(): array
    {
        $keys = $this->ensure();
        $now = time();
        $chosen = null;
        foreach ($keys as $key) {
            if ($key['activeFrom'] > $now) {
                continue;
            }
            if (null === $chosen || $key['activeFrom'] > $chosen['activeFrom']) {
                $chosen = $key;
            }
        }
        $chosen ??= $keys[array_key_last($keys)];

        return ['publicKey' => $chosen['publicKey'], 'secretKey' => $chosen['secretKey']];
    }

    /**
     * The new key is listed at once and used for signing from the next hour.
     * The previous key stays listed for 24 hours.
     */
    public function rotate(): void
    {
        if ($this->redis->isAvailable() && !$this->redis->setIfAbsent('platform_links:notify_key_lock', '1', 30)) {
            throw new \RuntimeException('A notification key change is already running.');
        }
        $keys = $this->ensure();
        $now = time();
        foreach ($keys as $index => $key) {
            if (null === $key['retireAt']) {
                $keys[$index]['retireAt'] = $now + self::RETIRE_AFTER;
            }
        }
        $pair = $this->signer->generate();
        $keys[] = [
            'publicKey' => $pair['publicKey'],
            'secretKey' => $pair['secretKey'],
            'activeFrom' => $now + self::SIGN_DELAY,
            'retireAt' => null,
        ];
        $this->save($keys);
    }

    /**
     * @return list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}>
     */
    private function ensure(): array
    {
        $keys = $this->live($this->load());
        if ([] !== $keys) {
            return $keys;
        }
        $pair = $this->signer->generate();
        $keys = [[
            'publicKey' => $pair['publicKey'],
            'secretKey' => $pair['secretKey'],
            'activeFrom' => time(),
            'retireAt' => null,
        ]];
        $stored = $this->configs->insertIfAbsent(
            0,
            self::GROUP,
            self::SETTING,
            $this->encryption->encrypt(json_encode($keys, JSON_THROW_ON_ERROR)),
        );
        $winner = $this->live($this->decode($stored));

        return [] !== $winner ? $winner : $keys;
    }

    /**
     * @param list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}> $keys
     *
     * @return list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}>
     */
    private function live(array $keys): array
    {
        $now = time();

        return array_values(array_filter(
            $keys,
            static fn (array $key): bool => null === $key['retireAt'] || $key['retireAt'] > $now,
        ));
    }

    /**
     * @return list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}>
     */
    private function load(): array
    {
        return $this->decode($this->configs->getValue(0, self::GROUP, self::SETTING));
    }

    /**
     * @return list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}>
     */
    private function decode(?string $stored): array
    {
        if (null === $stored || '' === $stored) {
            return [];
        }
        try {
            $json = $this->encryption->decrypt($stored);
        } catch (\RuntimeException) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $keys = [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_string($row['publicKey'] ?? null) || !is_string($row['secretKey'] ?? null)) {
                continue;
            }
            $keys[] = [
                'publicKey' => $row['publicKey'],
                'secretKey' => $row['secretKey'],
                'activeFrom' => (int) ($row['activeFrom'] ?? 0),
                'retireAt' => isset($row['retireAt']) ? (int) $row['retireAt'] : null,
            ];
        }

        return $keys;
    }

    /**
     * @param list<array{publicKey: string, secretKey: string, activeFrom: int, retireAt: ?int}> $keys
     */
    private function save(array $keys): void
    {
        $this->configs->setValue(0, self::GROUP, self::SETTING, $this->encryption->encrypt(json_encode($keys, JSON_THROW_ON_ERROR)));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Federation;

use App\Repository\ConfigRepository;
use App\Service\EncryptionService;

/**
 * The key pair lives encrypted in BCONFIG, same at-rest pattern as provider keys.
 */
final class FederationIdentityStore
{
    public const GROUP = 'FEDERATION';
    public const SETTING = 'IDENTITY';

    public function __construct(
        private ConfigRepository $configs,
        private EncryptionService $encryption,
    ) {
    }

    public function get(): ?FederationIdentity
    {
        $stored = $this->configs->getValue(0, self::GROUP, self::SETTING);
        if (null === $stored || '' === $stored) {
            return null;
        }
        try {
            $json = $this->encryption->decrypt($stored);
        } catch (\RuntimeException) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $publicKey = $data['publicKey'] ?? null;
        $secretKey = $data['secretKey'] ?? null;
        $name = $data['name'] ?? null;
        if (!is_string($publicKey) || !is_string($secretKey) || !is_string($name)) {
            return null;
        }

        return new FederationIdentity($publicKey, $secretKey, $name, true === ($data['opened'] ?? false));
    }

    public function save(FederationIdentity $identity): void
    {
        $json = json_encode([
            'publicKey' => $identity->publicKey,
            'secretKey' => $identity->secretKey,
            'name' => $identity->name,
            'opened' => $identity->opened,
        ], JSON_THROW_ON_ERROR);
        $this->configs->setValue(0, self::GROUP, self::SETTING, $this->encryption->encrypt($json));
    }
}

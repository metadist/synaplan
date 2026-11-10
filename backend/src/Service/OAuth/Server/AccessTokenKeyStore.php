<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Repository\ConfigRepository;
use App\Service\Security\EncryptionService;

/**
 * The RSA key Synaplan signs its OAuth2 access tokens with (RS256). Created
 * once, kept encrypted in BCONFIG; the public half is in Synaplan's JWK Set.
 */
final class AccessTokenKeyStore
{
    private const GROUP = 'OAUTH_SERVER';
    private const SETTING = 'ACCESS_TOKEN_KEY';

    /** @var array{private: string, public: string, kid: string}|null */
    private ?array $key = null;

    public function __construct(
        private readonly ConfigRepository $configs,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function privateKeyPem(): string
    {
        return $this->ensure()['private'];
    }

    public function publicKeyPem(): string
    {
        return $this->ensure()['public'];
    }

    public function kid(): string
    {
        return $this->ensure()['kid'];
    }

    /**
     * @return array{kty: string, n: string, e: string, kid: string, use: string, alg: string}
     */
    public function publicJwk(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->publicKeyPem()) ?: throw new \RuntimeException('Access token key is not readable.'));
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Access token key is not an RSA key.');
        }

        return [
            'kty' => 'RSA',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
            'kid' => $this->kid(),
            'use' => 'sig',
            'alg' => 'RS256',
        ];
    }

    /**
     * @return array{private: string, public: string, kid: string}
     */
    private function ensure(): array
    {
        if (null !== $this->key) {
            return $this->key;
        }
        $stored = $this->decode($this->configs->getValue(0, self::GROUP, self::SETTING));
        if (null === $stored) {
            $stored = $this->decode($this->configs->insertIfAbsent(0, self::GROUP, self::SETTING, $this->encryption->encrypt(
                json_encode($this->generate(), \JSON_THROW_ON_ERROR),
            ))) ?? throw new \RuntimeException('Access token key could not be stored.');
        }

        return $this->key = $stored;
    }

    /**
     * @return array{private: string, public: string, kid: string}
     */
    private function generate(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if (false === $resource || !openssl_pkey_export($resource, $private)) {
            throw new \RuntimeException('Could not create the access token key.');
        }
        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw new \RuntimeException('Could not read the access token key.');
        }

        return [
            'private' => (string) $private,
            'public' => $details['key'],
            'kid' => substr(self::b64(hash('sha256', $details['key'], true)), 0, 16),
        ];
    }

    /**
     * @return array{private: string, public: string, kid: string}|null
     */
    private function decode(?string $stored): ?array
    {
        if (null === $stored || '' === $stored) {
            return null;
        }
        try {
            $data = json_decode($this->encryption->decrypt($stored), true);
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['private'] ?? null) || !is_string($data['public'] ?? null) || !is_string($data['kid'] ?? null)) {
            return null;
        }

        return ['private' => $data['private'], 'public' => $data['public'], 'kid' => $data['kid']];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

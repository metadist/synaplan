<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\DeliveryAddressService;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\PlatformLinkRateLimiter;
use App\Service\PlatformLink\RedirectUriPolicy;

/**
 * Dynamic client registration for clouds (RFC 7591) and client
 * configuration (RFC 7592). A cloud registers its address (client_uri), the
 * redirect URIs people return to, the public keys it signs client
 * assertions with (jwks; private_key_jwt is the only client authentication),
 * and, as Synaplan extensions, where Synaplan notifies it (delivery_url),
 * what the app does (app_capabilities) and its server id.
 *
 * Registration grants no access to anyone's data: a person's data is
 * reachable only with that person's consent in the authorization code flow.
 */
final readonly class ClientRegistrationService
{
    public const CLIENTS = [PlatformInstance::CLIENT_NEXTCLOUD, PlatformInstance::CLIENT_OWNCLOUD];

    private const IP_LIMIT = 10;
    private const IP_WINDOW = 3600;

    public function __construct(
        private PlatformInstanceRepository $instances,
        private OAuthTokenRepository $tokens,
        private RedirectUriPolicy $redirects,
        private DeliveryAddressService $deliveryAddresses,
        private PlatformLinkRateLimiter $rateLimiter,
        private AuditLogWriter $audit,
        private RegistrationPolicy $policy,
        private string $issuer,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function register(array $metadata, ?string $bearer, string $ip): array
    {
        $status = $this->policy->statusFor($bearer);
        if (null === $status) {
            throw new RegistrationException('invalid_token', 'Registration needs an initial access token.', 401);
        }
        if (!$this->policy->presentsToken($bearer) && !$this->rateLimiter->allow(
            'oauth:register:'.sha1('' !== $ip ? $ip : 'unknown'),
            self::IP_LIMIT,
            self::IP_WINDOW,
        )) {
            throw new RegistrationException('invalid_client_metadata', 'Too many registrations from this address. Please wait and try again.', 429);
        }

        $client = $this->softwareId($metadata);
        $host = $this->host($metadata);
        $redirectUris = $this->redirectUris($host, $metadata);
        $jwks = $this->jwks($metadata);
        $this->assertProtocol($metadata);

        $instance = new PlatformInstance($client, 'pi_'.bin2hex(random_bytes(12)), $host, $redirectUris, $status, 0);
        $instance->setJwks($jwks);
        $this->instances->save($instance);
        $this->applyExtensions($instance, $metadata);

        $this->audit->record(0, 'platform_instance.registered', 'platform_instance', $instance->getInstanceId(), [
            'client' => $client,
            'host' => $host,
            'status' => $status,
        ], $ip);

        return $this->describe($instance) + ['client_id_issued_at' => time()];
    }

    public function policyMode(): string
    {
        return $this->policy->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(PlatformInstance $instance): array
    {
        $instance->touchLastSeen();
        $this->instances->save($instance);

        return $this->describe($instance);
    }

    /**
     * RFC 7592 update. client_uri and software_id stay as registered: a
     * different cloud registers anew.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function update(PlatformInstance $instance, array $metadata, string $ip): array
    {
        if (isset($metadata['client_id']) && $metadata['client_id'] !== $instance->getInstanceId()) {
            throw new RegistrationException('invalid_client_metadata', 'client_id does not match.');
        }
        if (isset($metadata['client_uri']) && $this->host($metadata) !== $instance->getHost()) {
            throw new RegistrationException('invalid_client_metadata', 'client_uri cannot change; register the new address as a new client.');
        }
        $this->assertProtocol($metadata);
        if (isset($metadata['redirect_uris'])) {
            $instance->setRedirectUris($this->redirectUris($instance->getHost(), $metadata));
        }
        if (isset($metadata['jwks'])) {
            $instance->setJwks($this->jwks($metadata));
        }
        $this->instances->save($instance);
        $this->applyExtensions($instance, $metadata);
        $this->audit->record(0, 'platform_instance.updated', 'platform_instance', $instance->getInstanceId(), null, $ip);

        return $this->describe($instance);
    }

    /**
     * RFC 7592 delete: the cloud leaves. Every token it holds stops working;
     * the people's links are ended by the administrator's revoke path.
     */
    public function delete(PlatformInstance $instance, string $ip): void
    {
        $instance->setStatus(PlatformInstance::STATUS_REVOKED);
        $this->instances->save($instance);
        $this->tokens->revokeAll($instance->getInstanceId(), null);
        $this->audit->record(0, 'platform_instance.unregistered', 'platform_instance', $instance->getInstanceId(), null, $ip);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PlatformInstance $instance): array
    {
        $issuer = rtrim($this->issuer, '/');

        return [
            'client_id' => $instance->getInstanceId(),
            'client_uri' => 'https://'.$instance->getHost(),
            'software_id' => $instance->getClient(),
            'redirect_uris' => $instance->getRedirectUris(),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => $instance->getJwks() ?? ['keys' => []],
            'registration_client_uri' => $issuer.'/oauth/register/'.$instance->getInstanceId(),
            'status' => $instance->getStatus(),
            'delivery_url' => $instance->getDeliveryUrl() ?? '',
            'app_capabilities' => $instance->getAppCapabilities(),
            'reachable' => $instance->isReachable(),
            'software_version' => $instance->getAppVersion() ?? '',
            'server_id' => $instance->getServerId() ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function applyExtensions(PlatformInstance $instance, array $metadata): void
    {
        $capabilities = $metadata['app_capabilities'] ?? null;
        try {
            $this->deliveryAddresses->update(
                $instance,
                is_string($metadata['delivery_url'] ?? null) ? $metadata['delivery_url'] : null,
                is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : null,
                null,
                is_string($metadata['software_version'] ?? null) ? $metadata['software_version'] : null,
                is_string($metadata['server_id'] ?? null) ? $metadata['server_id'] : null,
            );
        } catch (PlatformLinkValidationException $e) {
            throw new RegistrationException('invalid_client_metadata', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function softwareId(array $metadata): string
    {
        $client = strtolower(trim((string) ($metadata['software_id'] ?? '')));
        if (!in_array($client, self::CLIENTS, true)) {
            throw new RegistrationException('invalid_client_metadata', 'software_id must be nextcloud or owncloud.');
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function host(array $metadata): string
    {
        try {
            return $this->redirects->normalizeHost((string) ($metadata['client_uri'] ?? ''));
        } catch (PlatformLinkValidationException $e) {
            throw new RegistrationException('invalid_client_metadata', 'client_uri: '.$e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    private function redirectUris(string $host, array $metadata): array
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris)) {
            throw new RegistrationException('invalid_redirect_uri', 'redirect_uris is required.');
        }
        try {
            return $this->redirects->normalizeRedirectUris($host, array_values(array_filter($uris, 'is_string')));
        } catch (PlatformLinkValidationException $e) {
            throw new RegistrationException('invalid_redirect_uri', $e->getMessage());
        }
    }

    /**
     * Public signing keys only: RSA or EC, no private parts.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    private function jwks(array $metadata): array
    {
        $keys = is_array($metadata['jwks'] ?? null) && is_array($metadata['jwks']['keys'] ?? null) ? $metadata['jwks']['keys'] : [];
        $clean = [];
        foreach ($keys as $key) {
            if (!is_array($key) || !in_array($key['kty'] ?? null, ['RSA', 'EC'], true)) {
                throw new RegistrationException('invalid_client_metadata', 'jwks may hold RSA or EC public keys only.');
            }
            foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'k'] as $private) {
                if (array_key_exists($private, $key)) {
                    throw new RegistrationException('invalid_client_metadata', 'jwks must not contain private key material.');
                }
            }
            $clean[] = $key;
        }
        if ([] === $clean || count($clean) > 5) {
            throw new RegistrationException('invalid_client_metadata', 'jwks needs one to five public keys.');
        }

        return ['keys' => $clean];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function assertProtocol(array $metadata): void
    {
        $method = $metadata['token_endpoint_auth_method'] ?? 'private_key_jwt';
        if ('private_key_jwt' !== $method) {
            throw new RegistrationException('invalid_client_metadata', 'token_endpoint_auth_method must be private_key_jwt.');
        }
        foreach ((array) ($metadata['grant_types'] ?? []) as $grant) {
            if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                throw new RegistrationException('invalid_client_metadata', 'grant_types may be authorization_code and refresh_token.');
            }
        }
        foreach ((array) ($metadata['response_types'] ?? []) as $type) {
            if ('code' !== $type) {
                throw new RegistrationException('invalid_client_metadata', 'response_types may be code.');
            }
        }
    }
}

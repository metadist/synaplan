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
    private const MAX_REDIRECT_URIS = 10;

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
        $guessing = !$this->policy->presentsToken($bearer)
            && (null === $status || (null !== $bearer && '' !== trim($bearer)));
        if ($guessing && !$this->rateLimiter->allow(
            'oauth:register-token:'.sha1('' !== $ip ? $ip : 'unknown'),
            self::IP_LIMIT,
            self::IP_WINDOW,
        )) {
            throw new RegistrationException('invalid_token', 'Too many attempts to register with an initial access token. Please wait and try again.', 429);
        }
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
        $jwks = ClientKeys::normalize($metadata['jwks'] ?? null);
        $this->assertProtocol($metadata);

        // Stored by applyExtensions(), and only once every part was accepted:
        // a refused registration leaves no client behind.
        $instance = new PlatformInstance($client, 'pi_'.bin2hex(random_bytes(12)), $host, $redirectUris, $status, 0);
        $instance->setJwks($jwks);
        $this->applyExtensions($instance, $metadata);
        if ($this->policy->presentsToken($bearer) && !$this->tokens->claimRegistrationToken((string) $bearer)) {
            $this->instances->remove($instance);
            throw new RegistrationException('invalid_token', 'This initial access token was already used.', 401);
        }

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
        if (!$this->rateLimiter->allow('oauth:client-update:'.$instance->getInstanceId(), 30, self::IP_WINDOW)) {
            throw new RegistrationException('invalid_client_metadata', 'Too many updates for this client. Please wait and try again.', 429);
        }
        if (isset($metadata['client_id']) && $metadata['client_id'] !== $instance->getInstanceId()) {
            throw new RegistrationException('invalid_client_metadata', 'client_id does not match.');
        }
        if (isset($metadata['client_uri']) && $this->host($metadata) !== $instance->getHost()) {
            throw new RegistrationException('invalid_client_metadata', 'client_uri cannot change; register the new address as a new client.');
        }
        $this->assertProtocol($metadata);
        $redirectUris = isset($metadata['redirect_uris']) ? $this->redirectUris($instance->getHost(), $metadata) : null;
        $jwks = isset($metadata['jwks']) ? ClientKeys::normalize($metadata['jwks']) : null;

        // Everything is checked before anything changes: applyExtensions()
        // refuses a delivery address before it touches the client.
        $this->applyExtensions($instance, $metadata);
        if (null !== $redirectUris) {
            $instance->setRedirectUris($redirectUris);
        }
        if (null !== $jwks) {
            $instance->setJwks($jwks);
        }
        $this->instances->save($instance);
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
        if (count($uris) > self::MAX_REDIRECT_URIS) {
            throw new RegistrationException('invalid_redirect_uri', sprintf('At most %d redirect_uris can be registered.', self::MAX_REDIRECT_URIS));
        }
        try {
            return $this->redirects->normalizeRedirectUris($host, array_values(array_filter($uris, 'is_string')));
        } catch (PlatformLinkValidationException $e) {
            throw new RegistrationException('invalid_redirect_uri', $e->getMessage());
        }
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

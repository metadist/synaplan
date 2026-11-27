<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;

/**
 * The one cloud that shares this Synaplan's identity provider, set up by
 * environment variables only: no registration, no approval, no credential.
 * Like `outlook-builtin` it is a row with a fixed id (`oidc-cloud`), so
 * everything that refers to the cloud keeps its reference while the
 * configuration changes; `app:seed` brings the row in line with these values
 * on every container start (OidcCloudSeeder).
 *
 * - PLATFORM_LINKS_OIDC_CLOUD_TYPE: opencloud or nextcloud; empty is off
 * - PLATFORM_LINKS_OIDC_CLOUD_URL: the address people open
 * - PLATFORM_LINKS_OIDC_CLOUD_CLIENT_ID: the identity provider client its
 *   people's tokens name as azp
 * - PLATFORM_LINKS_OIDC_CLOUD_INTERNAL_URL (optional): where Synaplan
 *   reaches the cloud app inside the cluster, without a path; the public
 *   address otherwise
 * - PLATFORM_LINKS_OIDC_CLOUD_USER_CLAIM (optional): the token claim the
 *   cloud knows its people by; `sub` otherwise
 *
 * Synaplan notifies the cloud at the app's inbound path, which belongs to
 * the cloud type and changes with the app, not with the configuration.
 *
 * What that app takes is not known here. The app reports it
 * ({@see OidcCloudReport}) with a person's token whose azp is this client.
 * Until that report arrives, the core offers nothing that waits for an answer.
 */
final readonly class OidcCloud
{
    /** Where each cloud app takes Synaplan's notifications. */
    public const INBOUND_PATHS = [
        PlatformInstance::CLIENT_OPENCLOUD => '/api/synaplan/inbound',
        PlatformInstance::CLIENT_NEXTCLOUD => '/index.php/apps/synaplan_integration/api/v1/inbound',
    ];

    public const DEFAULT_USER_CLAIM = 'sub';

    public string $client;
    public string $url;
    public string $clientId;
    public string $deliveryUrl;
    public string $userClaim;

    public function __construct(string $type, string $url, string $clientId, string $internalUrl = '', string $userClaim = '')
    {
        $this->client = strtolower(trim($type));
        $this->url = rtrim(trim($url), '/');
        $this->clientId = trim($clientId);
        $base = '' !== trim($internalUrl) ? rtrim(trim($internalUrl), '/') : $this->url;
        $path = self::INBOUND_PATHS[$this->client] ?? null;
        $this->deliveryUrl = null !== $path && '' !== $base ? $base.$path : '';
        $this->userClaim = '' !== trim($userClaim) ? trim($userClaim) : self::DEFAULT_USER_CLAIM;
    }

    public function isEnabled(): bool
    {
        return isset(self::INBOUND_PATHS[$this->client]) && '' !== $this->url && '' !== $this->clientId;
    }

    public function isThisCloud(PlatformInstance $instance): bool
    {
        return $this->isEnabled() && PlatformInstance::OIDC_CLOUD_ID === $instance->getInstanceId();
    }

    /**
     * The token claim that names a person in the given cloud: the configured
     * one for this cloud, `sub` for any other.
     */
    public function userClaimFor(PlatformInstance $instance): string
    {
        return $this->isThisCloud($instance) ? $this->userClaim : self::DEFAULT_USER_CLAIM;
    }

    /**
     * Whether Synaplan may call this address for this instance: for the
     * configured cloud exactly its inbound address, nothing else.
     */
    public function allowsDelivery(PlatformInstance $instance, string $url): bool
    {
        return $this->isThisCloud($instance) && $url === $this->deliveryUrl;
    }
}

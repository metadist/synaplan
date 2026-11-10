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
 *
 * Synaplan notifies the cloud at the app's inbound path, which belongs to
 * the cloud type and changes with the app, not with the configuration.
 */
final readonly class OidcCloud
{
    public const ALL_CAPABILITIES = ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'];

    /** Where each cloud app takes Synaplan's notifications. */
    public const INBOUND_PATHS = [
        PlatformInstance::CLIENT_OPENCLOUD => '/api/synaplan/inbound',
        PlatformInstance::CLIENT_NEXTCLOUD => '/index.php/apps/synaplan_integration/api/v1/inbound',
    ];

    public string $client;
    public string $url;
    public string $clientId;
    public string $deliveryUrl;
    /** @var list<string> */
    public array $capabilities;

    public function __construct(string $type, string $url, string $clientId, string $internalUrl = '')
    {
        $this->client = strtolower(trim($type));
        $this->url = rtrim(trim($url), '/');
        $this->clientId = trim($clientId);
        $base = '' !== trim($internalUrl) ? rtrim(trim($internalUrl), '/') : $this->url;
        $path = self::INBOUND_PATHS[$this->client] ?? null;
        $this->deliveryUrl = null !== $path && '' !== $base ? $base.$path : '';
        $this->capabilities = self::ALL_CAPABILITIES;
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
     * Whether Synaplan may call this address for this instance: for the
     * configured cloud exactly its inbound address, nothing else.
     */
    public function allowsDelivery(PlatformInstance $instance, string $url): bool
    {
        return $this->isThisCloud($instance) && $url === $this->deliveryUrl;
    }
}

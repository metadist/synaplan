<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;

/**
 * The one cloud that shares this Synaplan's identity provider, set up by
 * environment variables only: no registration, no approval, no instance
 * secret. Like `outlook-builtin` it is a row with a fixed id (`oidc-cloud`),
 * so everything that refers to the cloud keeps its reference while the
 * configuration changes; `app:seed` brings the row in line with these values
 * on every container start (OidcCloudSeeder).
 *
 * - PLATFORM_LINKS_OIDC_CLOUD: opencloud, nextcloud or owncloud; empty is off
 * - PLATFORM_LINKS_OIDC_CLOUD_HOST: the address people open
 * - PLATFORM_LINKS_OIDC_CLOUD_PEOPLE_CLIENT: the client its people's tokens name as azp
 * - PLATFORM_LINKS_OIDC_CLOUD_CAPABILITIES: what the cloud app does, comma
 *   separated; empty means all of deliveries, pickups, read_scope,
 *   source_events and watches
 * - PLATFORM_LINKS_OIDC_CLOUD_DELIVERY_URL: where Synaplan calls the cloud,
 *   http and internal names included. Synaplan calls this address only; what
 *   the cloud announces about addresses is ignored.
 *
 * The cloud has no credential of its own here: it calls Synaplan only with
 * its people's tokens, so the row's instance routes (instances/self,
 * lifecycle) stay closed to it.
 */
final readonly class OidcCloud
{
    public string $client;
    public string $host;
    public string $peopleClient;
    public string $deliveryUrl;
    /** @var list<string> */
    public array $capabilities;

    public const ALL_CAPABILITIES = ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'];

    public function __construct(string $client, string $host, string $peopleClient, string $deliveryUrl, string $capabilities = '')
    {
        $this->client = strtolower(trim($client));
        $this->host = trim($host);
        $this->peopleClient = trim($peopleClient);
        $this->deliveryUrl = trim($deliveryUrl);
        $named = array_values(array_filter(array_map('trim', explode(',', $capabilities)), static fn (string $c): bool => '' !== $c));
        $this->capabilities = [] === $named ? self::ALL_CAPABILITIES : $named;
    }

    public function isEnabled(): bool
    {
        return in_array($this->client, [PlatformInstance::CLIENT_OPENCLOUD, PlatformInstance::CLIENT_NEXTCLOUD, PlatformInstance::CLIENT_OWNCLOUD], true)
            && '' !== $this->host && '' !== $this->peopleClient && '' !== $this->deliveryUrl;
    }

    public function isThisCloud(PlatformInstance $instance): bool
    {
        return $this->isEnabled() && PlatformInstance::OIDC_CLOUD_ID === $instance->getInstanceId();
    }

    /**
     * Whether Synaplan may call this address for this instance: for the
     * configured cloud exactly the configured address, nothing else.
     */
    public function allowsDelivery(PlatformInstance $instance, string $url): bool
    {
        return $this->isThisCloud($instance) && $url === $this->deliveryUrl;
    }
}

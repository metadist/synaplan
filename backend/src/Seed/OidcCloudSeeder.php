<?php

declare(strict_types=1);

namespace App\Seed;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\RedirectUriPolicy;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Brings the cloud configured by environment variables (OidcCloud) into its
 * fixed row `oidc-cloud` on every container start, after the platform-links
 * defaults. Nothing happens when PLATFORM_LINKS_OIDC_CLOUD is empty; a row
 * from an earlier configuration then stays as it is.
 */
#[AsTaggedItem(priority: -10)]
final readonly class OidcCloudSeeder implements SeederInterface
{
    public function __construct(
        private OidcCloud $cloud,
        private PlatformInstanceRepository $instances,
        private RedirectUriPolicy $redirects,
        private AuditLogWriter $audit,
    ) {
    }

    public function label(): string
    {
        return 'oidc-cloud';
    }

    public function description(): string
    {
        return 'BPLATFORMINSTANCES, row oidc-cloud — from PLATFORM_LINKS_OIDC_CLOUD_*';
    }

    public function seed(): SeedResult
    {
        if (!$this->cloud->isEnabled()) {
            return new SeedResult('oidc_cloud', inserted: 0, skipped: 1);
        }
        $host = $this->redirects->normalizeHost($this->cloud->host);
        $instance = $this->instances->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        $created = !$instance instanceof PlatformInstance;
        if (!$instance instanceof PlatformInstance) {
            $instance = new PlatformInstance($this->cloud->client, PlatformInstance::OIDC_CLOUD_ID, $host, [], PlatformInstance::STATUS_ACTIVE);
            $instance->setSecretHash(password_hash(bin2hex(random_bytes(32)), \PASSWORD_DEFAULT));
        }
        $instance->setClient($this->cloud->client);
        $instance->setHost($host);
        $instance->setStatus(PlatformInstance::STATUS_ACTIVE);
        $instance->setOidcClientId($this->cloud->peopleClient);
        $instance->setAppCapabilities($this->cloud->capabilities);
        $instance->setDeliveryUrl($this->cloud->deliveryUrl);
        $this->instances->save($instance);
        if ($created) {
            $this->audit->record(0, 'platform_instance.provisioned', 'platform_instance', PlatformInstance::OIDC_CLOUD_ID, [
                'client' => $this->cloud->client,
                'host' => $host,
            ]);
        }

        return new SeedResult('oidc_cloud', inserted: $created ? 1 : 0, updated: $created ? 0 : 1);
    }
}

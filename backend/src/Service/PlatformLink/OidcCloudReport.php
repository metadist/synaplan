<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\OidcCloudReportException;

/**
 * The configured cloud has no client credential of its own, so its app
 * speaks with a person's exchanged token. `azp` is the cloud's client
 * ({@see OidcCloud::$clientId}): only that client can mint the token, the
 * same way a registered cloud authenticates when it reports its address.
 *
 * Names the core does not know are dropped by {@see PlatformInstance::setAppCapabilities()}.
 * A newer app and an older core still agree on the names both know.
 */
final readonly class OidcCloudReport
{
    public function __construct(
        private OidcCloud $cloud,
        private PlatformInstanceRepository $instances,
        private DeliveryAddressService $deliveryAddresses,
        private AuditLogWriter $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $body
     *
     * @return array{app_capabilities: list<string>, software_version: string}
     */
    public function accept(array $claims, array $body, int $userId): array
    {
        if (!$this->cloud->isEnabled()) {
            throw new OidcCloudReportException('The configured cloud is not set up.', OidcCloudReportException::NOT_CONFIGURED);
        }
        $azp = $claims['azp'] ?? null;
        if (!is_string($azp) || $azp !== $this->cloud->clientId) {
            throw new OidcCloudReportException('This token is not from the configured cloud.', OidcCloudReportException::NOT_THIS_CLOUD);
        }
        $instance = $this->instances->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        if (!$instance instanceof PlatformInstance || !$instance->isActive() || !$this->cloud->isThisCloud($instance)) {
            throw new OidcCloudReportException('The configured cloud is not set up.', OidcCloudReportException::NOT_CONFIGURED);
        }

        $capabilities = $this->names($body);
        $version = $this->version($body);
        $reported = $this->deliveryAddresses->update($instance, null, $capabilities, null, $version, null);
        $this->audit->record($userId, 'platform_instance.app_reported', 'platform_instance', PlatformInstance::OIDC_CLOUD_ID, [
            'client' => $instance->getClient(),
            'app_capabilities' => $reported['app_capabilities'],
            'software_version' => $reported['app_version'],
        ]);

        return [
            'app_capabilities' => $reported['app_capabilities'],
            'software_version' => $reported['app_version'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function names(array $body): array
    {
        $names = $body['app_capabilities'] ?? null;
        if (!is_array($names) || !array_is_list($names)) {
            throw new OidcCloudReportException('app_capabilities must be a list of names.', OidcCloudReportException::BAD_REPORT);
        }
        $clean = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new OidcCloudReportException('app_capabilities must be a list of names.', OidcCloudReportException::BAD_REPORT);
            }
            $clean[] = $name;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function version(array $body): string
    {
        if (!array_key_exists('software_version', $body)) {
            return '';
        }
        if (!is_string($body['software_version'])) {
            throw new OidcCloudReportException('software_version must be a string.', OidcCloudReportException::BAD_REPORT);
        }

        return $body['software_version'];
    }
}

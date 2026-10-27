<?php

declare(strict_types=1);

namespace App\Module\CloudFiles;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\File\CloudFilesConfig;

/**
 * Searchable state for files that came from a cloud (the bird).
 *
 * Off by default. The status call, the file detail and source events answer
 * 404 while it is off, and the cloud's capability list omits them.
 */
final class CloudFilesModule implements FeatureModuleInterface
{
    public const ID = 'cloud_files';

    public function __construct(
        private readonly CloudFilesConfig $config,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.cloud_files.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['CLOUD_FILES_ENABLED'],
            bconfigKeys: [CloudFilesConfig::GROUP.'.'.CloudFilesConfig::ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        return $this->config->isEnabled();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('Cloud file state is turned off');
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: 'Cloud file state is on',
        );
    }

    public function capabilityIds(): array
    {
        return ['cloud_status'];
    }

    public function routeNames(): array
    {
        return [
            'api_cloud_status',
            'api_cloud_file_detail',
            'api_cloud_source_events',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\File\CloudFilesConfig',
            'App\Service\File\CloudFileStatus',
            'App\Service\File\CloudSourceEvents',
            'App\Controller\CloudFileController',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/cloud-files';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}

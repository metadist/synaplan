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
            envKeys: ['CLOUD_FILES_ENABLED', 'CLOUD_FILES_GLYPH_BIRD', 'CLOUD_FILES_GLYPH_EYES'],
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
        return ['cloud_status', 'watches'];
    }

    public function routeNames(): array
    {
        return [
            'api_cloud_status',
            'api_cloud_file_detail',
            'api_cloud_source_events',
            'api_cloud_watches_list',
            'api_cloud_watches_options',
            'api_cloud_watches_create',
            'api_cloud_watches_update',
            'api_cloud_watches_delete',
            'api_cloud_watches_files',
            'api_cloud_watches_reconcile',
            'api_cloud_watch_index',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\File\CloudFilesConfig',
            'App\Service\File\CloudFileStatus',
            'App\Service\File\CloudSourceEvents',
            'App\Controller\CloudFileController',
            'App\Controller\CloudWatchController',
            'App\Service\CloudWatch\CloudWatchService',
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

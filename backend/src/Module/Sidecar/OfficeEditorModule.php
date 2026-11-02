<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Service\Office\Wopi\OfficeEditorConfig;

/**
 * Collabora editor (WOPI). Off unless both the browser URL and the callback
 * URL are set. Conversion ({@see OfficeConvertModule}) stays independent:
 * thumbnails and PDF export do not open the editor.
 *
 * `status()` probes discovery on the URL the server can reach (the convert
 * sidecar when that is configured, otherwise the public URL).
 */
final class OfficeEditorModule implements FeatureModuleInterface
{
    public const ID = 'office_editor';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $publicUrl,
        private readonly string $wopiUrl,
        private readonly string $convertUrl,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.office_editor.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('OFFICE_PUBLIC_URL', 'OFFICE_WOPI_URL');
    }

    public function isConfigured(): bool
    {
        return OfficeEditorConfig::usable($this->publicUrl) && OfficeEditorConfig::usable($this->wopiUrl);
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('OFFICE_PUBLIC_URL or OFFICE_WOPI_URL is unset or disabled');
        }

        $source = OfficeEditorConfig::usable($this->convertUrl)
            ? rtrim(trim($this->convertUrl), '/')
            : rtrim(trim($this->publicUrl), '/');
        $healthy = $this->probe->isReachable($source.'/hosting/discovery');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Office editor is reachable' : 'Office editor discovery is not reachable',
            details: ['url' => rtrim(trim($this->publicUrl), '/')],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [
            'api_wopi_check_info',
            'api_wopi_contents',
            'api_wopi_contents_put',
            'api_wopi_lock',
            'api_files_edit_session',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Office\Wopi\OfficeEditorConfig',
            'App\Service\Office\Wopi\WopiTokenService',
            'App\Service\Office\Wopi\WopiDiscovery',
            'App\Service\Office\Wopi\WopiLockStore',
            'App\Service\Office\Wopi\WopiDocumentService',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/office-editor';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}

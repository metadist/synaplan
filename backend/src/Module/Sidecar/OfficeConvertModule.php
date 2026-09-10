<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Collabora / LibreOffice conversion sidecar — PDF export, thumbnails and
 * cross-format document combination.
 *
 * `isConfigured()` mirrors `OfficeConverterClient::isEnabled()`; `status()`
 * mirrors the historic `office-convert` block of the feature-status page
 * (`GET /hosting/capabilities`).
 *
 * No routes are listed: `api_files_export`, `api_files_thumb` and
 * `api_files_combine` already degrade per request (combine works for
 * same-format inputs without any engine), so a blanket gate would remove
 * behaviour that works today.
 */
final class OfficeConvertModule implements FeatureModuleInterface
{
    public const ID = 'office_convert';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $baseUrl,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.office_convert.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env('OFFICE_CONVERT_URL', 'OFFICE_CONVERT_TIMEOUT_MS');
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);

        return '' !== $url && 'disabled' !== $url;
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('OFFICE_CONVERT_URL is unset or disabled');
        }

        $url = trim($this->baseUrl);
        $healthy = $this->probe->isReachable(rtrim($url, '/').'/hosting/capabilities');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Office converter is running' : 'Office converter is not reachable',
            details: ['url' => $url],
        );
    }

    public function capabilityIds(): array
    {
        return ['pdf_export'];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\File\Office\OfficeConverterClient',
            'App\Service\File\Office\OfficePdfRoutingDecorator',
            'App\Service\File\Office\DocumentExportService',
            'App\Service\File\Office\DocumentThumbnailGenerator',
            'App\Service\File\Office\DocumentThumbnailDispatcher',
            'App\Service\Document\DocumentOfficeMergeService',
            'App\Service\Multitask\Execution\Runner\DocumentExportRunner',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/office-convert';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}

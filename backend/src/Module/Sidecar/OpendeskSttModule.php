<?php

declare(strict_types=1);

namespace App\Module\Sidecar;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Meeting notes for openDesk. The transcriber sidecar holds Jitsi and
 * Element audio; this module only records that the sidecar is pointed at
 * and exposes the operator snippet plus saved transcripts.
 *
 * Absent until OPENDESK_STT_URL is set. Cloud speech-to-text stays opt-in
 * on the Synaplan model the sidecar asks for.
 */
final class OpendeskSttModule implements FeatureModuleInterface
{
    public const ID = 'opendesk_stt';

    public function __construct(
        private readonly SidecarHealthProbeInterface $probe,
        private readonly string $baseUrl,
        private readonly string $publicUrl,
        private readonly string $language,
        private readonly string $mode,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.opendesk_stt.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'OPENDESK_STT_URL',
            'OPENDESK_STT_PUBLIC_URL',
            'OPENDESK_STT_LANGUAGE',
            'OPENDESK_STT_MODE',
        );
    }

    public function isConfigured(): bool
    {
        $url = trim($this->baseUrl);

        return '' !== $url && 'disabled' !== $url;
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('OPENDESK_STT_URL is unset or disabled');
        }

        $url = rtrim(trim($this->baseUrl), '/');
        $healthy = $this->probe->isReachable($url.'/health');

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'Meeting notes are running' : 'Meeting notes are not reachable',
            details: [
                'url' => $url,
                'public_url' => '' !== trim($this->publicUrl) ? rtrim(trim($this->publicUrl), '/') : $url,
                'mode' => $this->normalizedMode(),
                'language' => $this->normalizedLanguage(),
            ],
        );
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return ['api_opendesk_meeting_notes*'];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Opendesk\MeetingNoteStore',
            'App\Service\Opendesk\OpendeskConnectSnippet',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/opendesk-stt';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }

    public function normalizedLanguage(): string
    {
        return self::languageOf($this->language);
    }

    public function normalizedMode(): string
    {
        return self::modeOf($this->mode);
    }

    public static function languageOf(string $language): string
    {
        $value = strtolower(trim($language));

        return in_array($value, ['auto', 'de', 'en', 'es', 'fr', 'tr'], true) ? $value : 'auto';
    }

    public static function modeOf(string $mode): string
    {
        $value = strtolower(trim($mode));

        return in_array($value, ['jitsi', 'element', 'opendesk'], true) ? $value : 'opendesk';
    }
}

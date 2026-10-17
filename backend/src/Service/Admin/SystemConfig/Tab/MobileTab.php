<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Client\MobileVersionService;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Mobile App tab: the forced-update gate. Stored in BCONFIG group MOBILE
 * (owner 0), no restart — read by MobileVersionService and surfaced in the
 * public runtime config so the native app can block too-old installs (Epic
 * 8.2).
 */
#[AsTaggedItem(priority: 100)]
final readonly class MobileTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'mobile';
    }

    public function label(): string
    {
        return 'Mobile App';
    }

    public function sections(): array
    {
        return [
            'update' => $this->update(),
        ];
    }

    private function update(): ConfigSection
    {
        return new ConfigSection('Forced Update Gate', [
            'MIN_APP_VERSION' => new FieldDefinition(
                type: 'text',
                description: 'Minimum supported mobile app version (e.g. 4.0 or 4.1.2). Apps older than this are blocked with a "please update" screen. Leave empty to disable the gate.',
                default: MobileVersionService::DEFAULT_MIN_APP_VERSION,
                row: new DatabaseRow(MobileVersionService::GROUP, MobileVersionService::KEY_MIN_APP_VERSION),
            ),
            'IOS_APP_URL' => new FieldDefinition(
                type: 'url',
                description: 'App Store link shown on the forced-update screen (iOS).',
                default: MobileVersionService::DEFAULT_IOS_APP_URL,
                row: new DatabaseRow(MobileVersionService::GROUP, MobileVersionService::KEY_IOS_APP_URL),
            ),
            'ANDROID_APP_URL' => new FieldDefinition(
                type: 'url',
                description: 'Play Store link shown on the forced-update screen (Android).',
                default: MobileVersionService::DEFAULT_ANDROID_APP_URL,
                row: new DatabaseRow(MobileVersionService::GROUP, MobileVersionService::KEY_ANDROID_APP_URL),
            ),
        ]);
    }
}

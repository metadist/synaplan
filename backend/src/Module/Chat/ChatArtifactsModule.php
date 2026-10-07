<?php

declare(strict_types=1);

namespace App\Module\Chat;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Chat\ChatArtifactsConfig;

/**
 * Sandboxed HTML/SVG preview in chat. Off means the Preview action is absent.
 */
final class ChatArtifactsModule implements FeatureModuleInterface
{
    public const ID = 'chat_artifacts';

    public function __construct(
        private readonly ChatArtifactsConfig $config,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.chat_artifacts.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return new ConfiguredBy(
            envKeys: ['CHAT_ARTIFACTS_ENABLED'],
            bconfigKeys: [ChatArtifactsConfig::GROUP.'.'.ChatArtifactsConfig::ENABLED],
        );
    }

    public function isConfigured(): bool
    {
        return $this->config->isEnabled();
    }

    public function status(): ModuleStatus
    {
        if (!$this->isConfigured()) {
            return ModuleStatus::absent('Chat artifact preview is turned off');
        }

        return new ModuleStatus(configured: true, healthy: true, message: 'HTML and SVG previews are available');
    }

    public function capabilityIds(): array
    {
        return [];
    }

    public function routeNames(): array
    {
        return [];
    }

    public function serviceIds(): array
    {
        return [ChatArtifactsConfig::class];
    }

    public function docsAnchor(): string
    {
        return 'modules/chat-artifacts';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::BackendOnly;
    }
}

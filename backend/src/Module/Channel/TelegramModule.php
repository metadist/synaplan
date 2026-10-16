<?php

declare(strict_types=1);

namespace App\Module\Channel;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;
use App\Service\Telegram\PublicWebhookUrlValidator;

/**
 * Telegram channel — per-user BotFather bot, inbound webhook, chat reply.
 *
 * The bot token is a user credential, not an env secret. The install is
 * configured when TELEGRAM_ENABLED is on and Telegram can reach a public
 * https address (TELEGRAM_WEBHOOK_BASE_URL, else APP_URL). Otherwise the
 * card and the webhook are absent, so no one meets a form that cannot work.
 */
final class TelegramModule implements FeatureModuleInterface
{
    public const ID = 'telegram';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $appUrl = '',
        private readonly string $webhookBaseUrl = '',
        private readonly bool $allowLocalWebhook = false,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.telegram.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'TELEGRAM_ENABLED',
            'TELEGRAM_API_BASE_URL',
            'TELEGRAM_WEBHOOK_BASE_URL',
            'TELEGRAM_ALLOW_LOCAL_WEBHOOK',
        );
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->hasPublicWebhookBase();
    }

    public function status(): ModuleStatus
    {
        if (!$this->enabled) {
            return ModuleStatus::absent('Missing: TELEGRAM_ENABLED', [
                'missing' => ['TELEGRAM_ENABLED'],
            ]);
        }
        if (!$this->hasPublicWebhookBase()) {
            return ModuleStatus::absent('Missing: a public https APP_URL or TELEGRAM_WEBHOOK_BASE_URL that Telegram can reach', [
                'missing' => ['TELEGRAM_WEBHOOK_BASE_URL'],
            ]);
        }

        return new ModuleStatus(
            configured: true,
            healthy: true,
            message: 'Telegram channel enabled',
        );
    }

    private function hasPublicWebhookBase(): bool
    {
        $base = '' !== trim($this->webhookBaseUrl) ? $this->webhookBaseUrl : $this->appUrl;

        return (new PublicWebhookUrlValidator($this->allowLocalWebhook))->isPublic($base);
    }

    public function capabilityIds(): array
    {
        return ['channel_telegram'];
    }

    public function routeNames(): array
    {
        return [
            'api_webhooks_telegram',
            'api_telegram_channel_get',
            'api_telegram_channel_connect',
            'api_telegram_channel_renew_pairing',
            'api_telegram_channel_disconnect',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\Telegram\TelegramBotApi',
            'App\Service\Telegram\TelegramMessageFormatter',
            'App\Service\Telegram\PublicWebhookUrlValidator',
            'App\Service\Telegram\TelegramConnectionService',
            'App\Service\Telegram\TelegramInboundService',
            'App\Service\Telegram\TelegramWebhookAcceptor',
            'App\Controller\TelegramChannelController',
            'App\Controller\TelegramWebhookController',
            'App\MessageHandler\ProcessTelegramUpdateCommandHandler',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/telegram';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}

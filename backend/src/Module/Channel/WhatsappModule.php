<?php

declare(strict_types=1);

namespace App\Module\Channel;

use App\Module\Contract\ConfiguredBy;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Contract\MobileClass;
use App\Module\Contract\ModuleStatus;

/**
 * WhatsApp channel — Meta Cloud API inbound webhook, assistant binding and
 * phone verification.
 *
 * Deliberately env-only: `WhatsAppService` pulls in sixteen collaborators, so
 * the descriptor reads the same two values `WhatsAppService::isAvailable()`
 * uses instead of instantiating the service. `status()` names missing keys,
 * never their values.
 *
 * Never listed: `api_webhooks_whatsapp_verify` (Meta's GET handshake — the
 * very step that makes the channel configured) and `api_phone_verify_status`
 * (read-only, used by the profile page regardless of the channel).
 */
final class WhatsappModule implements FeatureModuleInterface
{
    public const ID = 'whatsapp';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $accessToken,
        private readonly string $webhookVerifyToken,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'modules.whatsapp.label';
    }

    public function configuredBy(): ConfiguredBy
    {
        return ConfiguredBy::env(
            'WHATSAPP_ENABLED',
            'WHATSAPP_ACCESS_TOKEN',
            'WHATSAPP_WEBHOOK_VERIFY_TOKEN',
            'WHATSAPP_GRAPH_API_BASE_URL',
        );
    }

    public function isConfigured(): bool
    {
        return $this->enabled && '' !== trim($this->accessToken);
    }

    public function status(): ModuleStatus
    {
        $missing = [];
        if (!$this->enabled) {
            $missing[] = 'WHATSAPP_ENABLED';
        }
        if ('' === trim($this->accessToken)) {
            $missing[] = 'WHATSAPP_ACCESS_TOKEN';
        }
        if ('' === trim($this->webhookVerifyToken)) {
            $missing[] = 'WHATSAPP_WEBHOOK_VERIFY_TOKEN';
        }

        if (!$this->isConfigured()) {
            return new ModuleStatus(
                configured: false,
                healthy: false,
                message: 'Missing: '.implode(', ', $missing),
                details: ['missing' => $missing],
            );
        }

        $healthy = [] === $missing;

        return new ModuleStatus(
            configured: true,
            healthy: $healthy,
            message: $healthy ? 'WhatsApp channel enabled' : 'WhatsApp enabled but webhook verify token missing',
            details: ['missing' => $missing],
        );
    }

    public function capabilityIds(): array
    {
        return ['channel_whatsapp'];
    }

    public function routeNames(): array
    {
        return [
            'api_webhooks_whatsapp',
            'api_whatsapp_assistant_*',
            'api_phone_verify_request',
            'api_phone_verify_confirm',
            'api_phone_verify_remove',
        ];
    }

    public function serviceIds(): array
    {
        return [
            'App\Service\WhatsAppService',
            'App\Controller\WebhookController',
            'App\Controller\WhatsAppAssistantController',
            'App\Controller\PhoneVerificationController',
            'App\Service\WhatsApp\WhatsAppAgentBinding',
        ];
    }

    public function docsAnchor(): string
    {
        return 'modules/whatsapp';
    }

    public function mobileClass(): MobileClass
    {
        return MobileClass::OtaCandidate;
    }
}

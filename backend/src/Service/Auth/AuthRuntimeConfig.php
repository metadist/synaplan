<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\AI\Credential\ChatReadinessService;
use App\Repository\ConfigRepository;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Chat\GuestChatConfig;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\Email\MailerConfig;
use App\Service\Setup\SetupStateService;

/**
 * Sign-in, guest trial, mail, the human check (Turnstile / reCAPTCHA), the
 * setup wizard and the signed-in chat-ready signal on the public runtime
 * payload.
 */
final readonly class AuthRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private RegistrationConfig $registrationConfig,
        private GuestChatConfig $guestChatConfig,
        private MailerConfig $mailerConfig,
        private DemoLoginHint $demoLoginHint,
        private SetupStateService $setupState,
        private ConfigRepository $configRepository,
        private ChatReadinessService $chatReadiness,
        private TurnstileVerifier $turnstile,
        private string $turnstileSiteKey,
        private PayFirstConfig $payFirst,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $user = $context->user;
        // Turnstile wins over reCAPTCHA when both are configured — the same
        // order as SignupChallenge on the server, so the browser never sends a
        // token the server cannot verify.
        $turnstileConfigured = $this->turnstile->isConfigured() && '' !== trim($this->turnstileSiteKey);
        $recaptchaEnabled = ($_ENV['RECAPTCHA_ENABLED'] ?? 'false') === 'true';
        $recaptchaSiteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';
        $recaptchaConfigured = !$turnstileConfigured && $recaptchaEnabled && !empty($recaptchaSiteKey) && 'your_site_key_here' !== $recaptchaSiteKey;

        $googleTagEnabled = '1' === $this->configRepository->getValue(0, 'GOOGLE_TAG', 'ENABLED');
        $googleTagIdRaw = $this->configRepository->getValue(0, 'GOOGLE_TAG', 'TAG_ID') ?? '';
        $googleTagId = '';
        if (!empty($googleTagIdRaw) && preg_match('/^(GTM-[A-Z0-9]+|G-[A-Z0-9]+)$/i', $googleTagIdRaw)) {
            $googleTagId = $googleTagIdRaw;
        }

        $setup = [
            'wizardRequired' => $this->setupState->isSetupRequired(),
            'wizardEnabled' => $this->setupState->isWizardEnabled(),
            'demoLoginHint' => $this->demoLoginHint->isVisible(),
        ];
        $payload = [
            'auth' => [
                'registrationEnabled' => $this->registrationConfig->isEnabled(),
                'registrationMode' => $this->registrationConfig->mode(),
                'payFirst' => $this->payFirst->isActive(),
                'guestChatEnabled' => $this->guestChatConfig->isEnabled(),
                'mailerConfigured' => $this->mailerConfig->isConfigured(),
            ],
            'recaptcha' => [
                'enabled' => $recaptchaConfigured,
                'siteKey' => $recaptchaConfigured ? $recaptchaSiteKey : '',
            ],
            'turnstile' => [
                'enabled' => $turnstileConfigured,
                'siteKey' => $turnstileConfigured ? trim($this->turnstileSiteKey) : '',
            ],
            'googleTag' => [
                'enabled' => $googleTagEnabled && !empty($googleTagId),
                'tagId' => ($googleTagEnabled && !empty($googleTagId)) ? $googleTagId : '',
            ],
        ];

        if (null !== $user) {
            $unavailable = $this->chatReadiness->unavailableProviderNames();
            if ([] !== $unavailable) {
                $payload['unavailableProviders'] = $unavailable;
            }
            $setup['chatReady'] = $this->chatReadiness->isChatReady(userId: $user->getId());
        }

        $payload['setup'] = $setup;

        return $payload;
    }
}

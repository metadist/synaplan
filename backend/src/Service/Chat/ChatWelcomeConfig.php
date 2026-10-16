<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Repository\ConfigRepository;

/**
 * What the empty chat may promote.
 *
 * A missing row keeps the website-widget promotion visible. An admin turns
 * it off per installation. Seeder defaults do not rewrite existing installs,
 * so "off" is an explicit row. SHOW_STORE_CARDS stays on the runtime payload
 * for older clients; the web empty chat no longer renders those cards.
 */
final readonly class ChatWelcomeConfig
{
    public const GROUP = 'CHAT_WELCOME';
    public const SHOW_STORE_CARDS = 'SHOW_STORE_CARDS';
    public const SHOW_WIDGET_PROMO = 'SHOW_WIDGET_PROMO';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function showStoreCards(): bool
    {
        return $this->flag(self::SHOW_STORE_CARDS);
    }

    public function showWidgetPromo(): bool
    {
        return $this->flag(self::SHOW_WIDGET_PROMO);
    }

    private function flag(string $setting): bool
    {
        $raw = $this->configRepository->getValue(0, self::GROUP, $setting);
        if (null === $raw || '' === trim($raw)) {
            return true;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}

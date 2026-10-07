<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Repository\ConfigRepository;

/**
 * What the empty chat may promote.
 *
 * A missing row keeps today's screen: store cards and the website-widget
 * promotion stay visible. An admin turns them off per installation. Seeder
 * defaults do not rewrite existing installs, so "off" is an explicit row.
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

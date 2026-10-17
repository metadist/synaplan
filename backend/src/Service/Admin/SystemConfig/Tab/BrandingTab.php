<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Branding\BrandingService;
use App\Service\Chat\ChatActionPolicy;
use App\Service\Chat\ChatArtifactsConfig;
use App\Service\Chat\ChatWelcomeConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Branding tab: brand identity, colors, fonts, logos, legal links, the
 * start page, the empty chat, the chat actions and the "Powered by"
 * attribution.
 *
 * Every field is a BCONFIG row (owner 0), no restart: the BRANDING rows
 * BrandingService reads and the public runtime config surfaces to the
 * frontend, the empty-chat cards (ChatWelcomeConfig) and the chat actions
 * (ChatActionPolicy, ChatArtifactsConfig).
 */
#[AsTaggedItem(priority: 110)]
final readonly class BrandingTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'branding';
    }

    public function label(): string
    {
        return 'Branding';
    }

    public function sections(): array
    {
        return [
            'identity' => $this->identity(),
            'colors' => $this->colors(),
            'fonts' => $this->fonts(),
            'logos' => $this->logos(),
            'legal' => $this->legal(),
            'navigation' => $this->navigation(),
            'welcome' => $this->welcome(),
            'chat' => $this->chat(),
            'attribution' => $this->attribution(),
        ];
    }

    private function identity(): ConfigSection
    {
        return new ConfigSection('Brand Identity', [
            'BRAND_NAME' => new FieldDefinition(
                type: 'text',
                description: 'Displayed brand/product name (document title, auth screens, attribution).',
                default: BrandingService::DEFAULT_NAME,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_NAME),
            ),
            'BRAND_TAGLINE' => new FieldDefinition(
                type: 'text',
                description: 'Optional short tagline/description shown beside the brand.',
                default: BrandingService::DEFAULT_TAGLINE,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_TAGLINE),
            ),
            'BRAND_HOMEPAGE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Brand homepage link used on auth and footer surfaces.',
                default: BrandingService::DEFAULT_HOMEPAGE_URL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_HOMEPAGE_URL),
            ),
        ]);
    }

    private function colors(): ConfigSection
    {
        return new ConfigSection('Colors', [
            'BRAND_PRIMARY_COLOR' => new FieldDefinition(
                type: 'text',
                description: 'Primary accent color as a hex value (e.g. #003fc7). Injected into the --brand CSS variables at runtime.',
                default: BrandingService::DEFAULT_PRIMARY_COLOR,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_PRIMARY_COLOR),
            ),
            'BRAND_SECONDARY_COLOR' => new FieldDefinition(
                type: 'text',
                description: 'Optional secondary color as a hex value. Leave empty to keep the default palette.',
                default: BrandingService::DEFAULT_SECONDARY_COLOR,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_SECONDARY_COLOR),
            ),
            'BRAND_ACCENT_COLOR' => new FieldDefinition(
                type: 'text',
                description: 'Optional accent color as a hex value. Leave empty to keep the default palette.',
                default: BrandingService::DEFAULT_ACCENT_COLOR,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_ACCENT_COLOR),
            ),
            'BRAND_PRIMARY_COLOR_DARK' => new FieldDefinition(
                type: 'text',
                description: 'Primary accent color used in DARK mode as a hex value. Leave empty to auto-derive a dark-friendly tint from the light primary color.',
                default: BrandingService::DEFAULT_PRIMARY_COLOR_DARK,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_PRIMARY_COLOR_DARK),
            ),
            'BRAND_SECONDARY_COLOR_DARK' => new FieldDefinition(
                type: 'text',
                description: 'Optional secondary color used in DARK mode as a hex value. Leave empty to reuse the light secondary color.',
                default: BrandingService::DEFAULT_SECONDARY_COLOR_DARK,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_SECONDARY_COLOR_DARK),
            ),
            'BRAND_ACCENT_COLOR_DARK' => new FieldDefinition(
                type: 'text',
                description: 'Optional accent color used in DARK mode as a hex value. Leave empty to reuse the light accent color.',
                default: BrandingService::DEFAULT_ACCENT_COLOR_DARK,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_ACCENT_COLOR_DARK),
            ),
        ]);
    }

    private function fonts(): ConfigSection
    {
        return new ConfigSection('Fonts', [
            'BRAND_FONT_FAMILY' => new FieldDefinition(
                type: 'text',
                description: 'Body font-family CSS stack (e.g. "Inter, sans-serif"). Leave empty to keep the default font.',
                default: BrandingService::DEFAULT_FONT_FAMILY,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_FONT_FAMILY),
            ),
            'BRAND_HEADING_FONT_FAMILY' => new FieldDefinition(
                type: 'text',
                description: 'Optional heading font-family CSS stack. Leave empty to fall back to the body font/default.',
                default: BrandingService::DEFAULT_HEADING_FONT_FAMILY,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_HEADING_FONT_FAMILY),
            ),
            'BRAND_FONT_URL' => new FieldDefinition(
                type: 'url',
                description: 'Optional web-font stylesheet URL (self-hosted or provider). The origin must be on the CSP allow-list; for the app, on the configured server\'s allowed origins. Leave empty for no external font.',
                default: BrandingService::DEFAULT_FONT_URL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_FONT_URL),
            ),
        ]);
    }

    private function logos(): ConfigSection
    {
        return new ConfigSection('Logos & Icon', [
            'BRAND_LOGO_URL' => new FieldDefinition(
                type: 'url',
                description: 'Light-mode logo URL. Leave empty to use the bundled Synaplan logo.',
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_LOGO_URL),
            ),
            'BRAND_LOGO_DARK_URL' => new FieldDefinition(
                type: 'url',
                description: 'Dark-mode logo URL. Leave empty to use the bundled Synaplan logo.',
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_LOGO_DARK_URL),
            ),
            'BRAND_ICON_URL' => new FieldDefinition(
                type: 'url',
                description: 'Brand icon URL (SVG recommended). Used for the sidebar mark, the favicon and the faint background watermark. Empty falls back to the light logo, then to the bundled Synaplan bird.',
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_ICON_URL),
            ),
        ]);
    }

    private function legal(): ConfigSection
    {
        return new ConfigSection('Legal Links', [
            'BRAND_PRIVACY_URL' => new FieldDefinition(
                type: 'url',
                description: 'Privacy-policy link. Reachable in-app (Settings) and used in store metadata; store policy (Apple/Google) requires it. White-label brands point this at their own page.',
                default: BrandingService::DEFAULT_PRIVACY_URL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_PRIVACY_URL),
            ),
            'BRAND_TERMS_URL' => new FieldDefinition(
                type: 'url',
                description: 'Terms-of-use link. Reachable in-app (Settings) and used in store metadata. White-label brands point this at their own page.',
                default: BrandingService::DEFAULT_TERMS_URL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_TERMS_URL),
            ),
        ]);
    }

    private function navigation(): ConfigSection
    {
        return new ConfigSection('Start Page', [
            'BRAND_LANDING_PAGE' => new FieldDefinition(
                type: 'text',
                description: 'Logged-out landing: a route name (e.g. "login") or a free-form path starting with "/" (e.g. "/welcome"). Must be a public page. Leave empty to keep the default; unknown/non-public values fail safe to the default.',
                default: BrandingService::DEFAULT_LANDING_PAGE,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_LANDING_PAGE),
            ),
            'BRAND_DEFAULT_ROUTE' => new FieldDefinition(
                type: 'text',
                description: 'Post-login default: a route name (e.g. "chat") or a free-form path starting with "/" (e.g. "/files"). Leave empty to keep the default; unknown values fail safe to the default.',
                default: BrandingService::DEFAULT_DEFAULT_ROUTE,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_DEFAULT_ROUTE),
            ),
        ]);
    }

    private function welcome(): ConfigSection
    {
        return new ConfigSection('Empty chat', [
            'CHAT_WELCOME_SHOW_STORE_CARDS' => new FieldDefinition(
                type: 'boolean',
                description: 'Show the App Store, Google Play and GitHub cards on an empty chat. On when this row is missing, so existing installations stay as they are until you turn it off.',
                default: 'true',
                row: new DatabaseRow(ChatWelcomeConfig::GROUP, ChatWelcomeConfig::SHOW_STORE_CARDS),
            ),
            'CHAT_WELCOME_SHOW_WIDGET_PROMO' => new FieldDefinition(
                type: 'boolean',
                description: 'Show the "embed a chat widget on your website" promotion above the composer. On when this row is missing.',
                default: 'true',
                row: new DatabaseRow(ChatWelcomeConfig::GROUP, ChatWelcomeConfig::SHOW_WIDGET_PROMO),
            ),
        ]);
    }

    private function chat(): ConfigSection
    {
        return new ConfigSection('Chat', [
            'CHAT_EXPORT_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let people download a chat (Markdown, PDF, JSON) and include chats in account export. A group policy can turn this off for one group. On when this row is missing.',
                default: 'true',
                row: new DatabaseRow(ChatActionPolicy::GROUP, ChatActionPolicy::EXPORT),
            ),
            'CHAT_SHARE_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let people create a public link for a chat. A group policy can turn this off for one group. On when this row is missing.',
                default: 'true',
                row: new DatabaseRow(ChatActionPolicy::GROUP, ChatActionPolicy::SHARE),
            ),
            'CHAT_ARTIFACTS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Offer a sandboxed preview for HTML and SVG answers. Off when this row is missing and CHAT_ARTIFACTS_ENABLED is empty. An explicit on in either place turns the preview on. An explicit off in either place turns it off.',
                default: 'false',
                row: new DatabaseRow(ChatArtifactsConfig::GROUP, ChatArtifactsConfig::ENABLED),
            ),
        ]);
    }

    private function attribution(): ConfigSection
    {
        return new ConfigSection('Attribution ("Powered by")', [
            'BRAND_SHOW_POWERED_BY' => new FieldDefinition(
                type: 'boolean',
                description: 'Show the "· powered by <label>" attribution across auth, logged-out, shared-chat and widget surfaces.',
                default: BrandingService::DEFAULT_SHOW_POWERED_BY,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_SHOW_POWERED_BY),
            ),
            'BRAND_POWERED_BY_LABEL' => new FieldDefinition(
                type: 'text',
                description: 'Attribution label — the platform being credited (e.g. "Synaplan").',
                default: BrandingService::DEFAULT_POWERED_BY_LABEL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_POWERED_BY_LABEL),
            ),
            'BRAND_POWERED_BY_URL' => new FieldDefinition(
                type: 'url',
                description: 'Attribution link target for the "powered by" label.',
                default: BrandingService::DEFAULT_POWERED_BY_URL,
                row: new DatabaseRow(BrandingService::GROUP, BrandingService::KEY_POWERED_BY_URL),
            ),
        ]);
    }
}

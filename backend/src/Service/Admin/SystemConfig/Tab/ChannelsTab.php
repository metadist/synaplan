<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Dropbox\DropboxOAuthConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Inbound Channels tab: WhatsApp, Telegram, Smart Mail (Gmail IMAP), the
 * Microsoft 365 and Dropbox app registrations and the MCP client switches.
 */
#[AsTaggedItem(priority: 80)]
final readonly class ChannelsTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'channels';
    }

    public function label(): string
    {
        return 'Inbound Channels';
    }

    public function sections(): array
    {
        return [
            'whatsapp' => $this->whatsapp(),
            'telegram' => $this->telegram(),
            'gmail' => $this->gmail(),
            'm365' => $this->m365(),
            'dropbox' => $this->dropbox(),
            'mcp' => $this->mcp(),
        ];
    }

    private function whatsapp(): ConfigSection
    {
        return new ConfigSection('WhatsApp Business API', [
            'WHATSAPP_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Enable WhatsApp integration',
                default: 'false',
            ),
            'WHATSAPP_ACCESS_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'WhatsApp access token',
                sensitive: true,
            ),
            'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'Webhook verification token',
                sensitive: true,
            ),
        ]);
    }

    private function telegram(): ConfigSection
    {
        return new ConfigSection('Telegram', [
            'TELEGRAM_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let people connect their own Telegram bot',
                default: 'false',
            ),
            'TELEGRAM_API_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Telegram Bot API base URL',
                default: 'https://api.telegram.org',
            ),
            'TELEGRAM_WEBHOOK_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Public base URL Telegram calls. Empty uses APP_URL.',
            ),
            'TELEGRAM_ALLOW_LOCAL_WEBHOOK' => new FieldDefinition(
                type: 'boolean',
                description: 'Allow a local APP_URL for the Telegram webhook (development only)',
                default: 'false',
            ),
        ]);
    }

    private function gmail(): ConfigSection
    {
        return new ConfigSection('Smart Mail (Gmail IMAP)', [
            'GMAIL_USERNAME' => new FieldDefinition(
                type: 'email',
                description: 'Gmail address for Smart Mail',
            ),
            'GMAIL_PASSWORD' => new FieldDefinition(
                type: 'password',
                description: 'Gmail App Password',
                sensitive: true,
            ),
        ]);
    }

    /**
     * The Microsoft 365 app registration: BCONFIG group M365 (owner 0), read
     * by MicrosoftOAuthConfig. Operator-owned and install-wide: Synaplan
     * Cloud runs a multi-tenant registration, self-hosters register their own
     * (connector plan 07 §S3). Users never see these values — they only click
     * "Connect Microsoft 365" and consent.
     */
    private function m365(): ConfigSection
    {
        return new ConfigSection('Microsoft 365 (Graph)', [
            'M365_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Offer Microsoft 365 as a connection. Requires a client ID and client secret from an Azure app registration; the "Connect Microsoft 365" action stays hidden until all three are set.',
                default: 'false',
                row: new DatabaseRow(MicrosoftOAuthConfig::CONFIG_GROUP, MicrosoftOAuthConfig::KEY_ENABLED),
            ),
            'M365_CLIENT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Application (client) ID of the Azure app registration. Azure portal → App registrations → your app → Overview.',
                row: new DatabaseRow(MicrosoftOAuthConfig::CONFIG_GROUP, MicrosoftOAuthConfig::KEY_CLIENT_ID),
                placeholder: '11111111-2222-3333-4444-555555555555',
            ),
            'M365_CLIENT_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'Client secret from Azure portal → your app → Certificates & secrets → New client secret. Copy the "Value" column, NOT the "Secret ID". Stored encrypted and never shown again; leave the field untouched to keep the current one.',
                sensitive: true,
                row: new DatabaseRow(MicrosoftOAuthConfig::CONFIG_GROUP, MicrosoftOAuthConfig::KEY_CLIENT_SECRET, encrypted: true),
                placeholder: 'Example: 8Qm~aB1cD2eF3gH4iJ5kL6mN7oP8qR9sT0uV1wX2',
            ),
            'M365_TENANT' => new FieldDefinition(
                type: 'text',
                description: 'Which accounts may sign in: "common" (work, school and personal accounts), "organizations" (work and school only), or a single tenant GUID to allow only your own organisation. Self-hosters normally use their own tenant GUID (Azure portal → your app → Overview → Directory (tenant) ID).',
                default: MicrosoftOAuthConfig::DEFAULT_TENANT,
                row: new DatabaseRow(MicrosoftOAuthConfig::CONFIG_GROUP, MicrosoftOAuthConfig::KEY_TENANT),
                placeholder: 'common — or 11111111-2222-3333-4444-555555555555',
            ),
            'M365_REDIRECT_URI' => new FieldDefinition(
                type: 'text',
                description: 'Only needed when a proxy changes the public URL. Leave empty to use APP_URL + '.MicrosoftOAuthConfig::CALLBACK_PATH.'. Whatever is used here must be registered in Azure character for character.',
                row: new DatabaseRow(MicrosoftOAuthConfig::CONFIG_GROUP, MicrosoftOAuthConfig::KEY_REDIRECT_URI),
                placeholder: 'https://your-synaplan-host'.MicrosoftOAuthConfig::CALLBACK_PATH,
            ),
        ]);
    }

    /**
     * The Dropbox app: BCONFIG group DROPBOX (owner 0), read by
     * DropboxOAuthConfig. Operator-owned and install-wide, like the Microsoft
     * 365 registration (connector plan 07 C13). Users never see these values
     * — they only click "Connect Dropbox" and consent.
     */
    private function dropbox(): ConfigSection
    {
        return new ConfigSection('Dropbox', [
            'DROPBOX_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Offer Dropbox as a connection. Requires an app key and app secret from a Dropbox app; the "Connect Dropbox" action stays hidden until all three are set.',
                default: 'false',
                row: new DatabaseRow(DropboxOAuthConfig::CONFIG_GROUP, DropboxOAuthConfig::KEY_ENABLED),
            ),
            'DROPBOX_APP_KEY' => new FieldDefinition(
                type: 'text',
                description: 'App key of the Dropbox app. Dropbox App Console (dropbox.com/developers/apps) → your app → Settings.',
                row: new DatabaseRow(DropboxOAuthConfig::CONFIG_GROUP, DropboxOAuthConfig::KEY_APP_KEY),
                placeholder: 'a1b2c3d4e5f6g7h',
            ),
            'DROPBOX_APP_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'App secret from the Dropbox App Console → your app → Settings → App secret (click "Show"). Stored encrypted and never shown again; leave the field untouched to keep the current one.',
                sensitive: true,
                row: new DatabaseRow(DropboxOAuthConfig::CONFIG_GROUP, DropboxOAuthConfig::KEY_APP_SECRET, encrypted: true),
                placeholder: 'Example: z9y8x7w6v5u4t3s',
            ),
            'DROPBOX_REDIRECT_URI' => new FieldDefinition(
                type: 'text',
                description: 'Only needed when a proxy changes the public URL. Leave empty to use APP_URL + '.DropboxOAuthConfig::CALLBACK_PATH.'. Whatever is used here must be registered in the Dropbox App Console character for character.',
                row: new DatabaseRow(DropboxOAuthConfig::CONFIG_GROUP, DropboxOAuthConfig::KEY_REDIRECT_URI),
                placeholder: 'https://your-synaplan-host'.DropboxOAuthConfig::CALLBACK_PATH,
            ),
        ]);
    }

    /**
     * The outbound MCP client master switch (BCONFIG group MCP /
     * CLIENT_ENABLED), also toggled from Manage → Connections → MCP Servers.
     * Seeded ON for new installs; an explicit 0 row is the operator kill
     * switch.
     */
    private function mcp(): ConfigSection
    {
        return new ConfigSection('MCP servers', [
            'MCP_CLIENT_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Allow the assistant to call connected MCP servers (Jira, Confluence, CRM, and any other MCP endpoint). When off, saved connections stay in place but no calls are made. You can also turn this on from Manage → Connections → MCP Servers.',
                default: 'true',
                row: new DatabaseRow(McpClientConfig::CONFIG_GROUP, McpClientConfig::KEY_CLIENT_ENABLED),
            ),
            'MCP_OAUTH_CONNECTORS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let users connect remote MCP servers that sign in with OAuth (Notion, Higgsfield, and any other standard remote MCP). When off, users can still add servers that use an access token. Seeded off — turn this on after you have reviewed the connections page.',
                default: 'false',
                row: new DatabaseRow(McpClientConfig::CONFIG_GROUP, McpClientConfig::KEY_OAUTH_CONNECTORS_ENABLED),
            ),
        ]);
    }
}

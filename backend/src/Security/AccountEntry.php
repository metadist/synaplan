<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A way into the product. Sign-in doors may open for an account that still
 * has to pay. Credential and channel doors require an active account: a key,
 * a widget or an inbound message must not spend the platform for someone
 * who is suspended, banned, or waiting on checkout.
 */
enum AccountEntry: string
{
    case Password = 'password';
    case Refresh = 'refresh';
    case Google = 'google';
    case GitHub = 'github';
    case Apple = 'apple';
    case OidcBrowser = 'oidc_browser';
    case OidcBearer = 'oidc_bearer';
    case ApiKey = 'api_key';
    case Mcp = 'mcp';
    case DesktopPair = 'desktop_pair';
    case AddinConnect = 'addin_connect';
    case PlatformLinkExchange = 'platform_link_exchange';
    case WidgetOwner = 'widget_owner';
    case InboundMail = 'inbound_mail';
    case InboundWhatsApp = 'inbound_whatsapp';
    case InboundTelegram = 'inbound_telegram';
    case ImpersonationStart = 'impersonation_start';
    case ImpersonationExit = 'impersonation_exit';

    public function allowsPendingPayment(): bool
    {
        return match ($this) {
            self::Password,
            self::Refresh,
            self::Google,
            self::GitHub,
            self::Apple,
            self::OidcBrowser,
            self::OidcBearer,
            self::ImpersonationStart,
            self::ImpersonationExit => true,
            default => false,
        };
    }
}

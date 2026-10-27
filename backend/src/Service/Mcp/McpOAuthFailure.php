<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Why a server sign-in could not start or finish. The value is the API
 * `code` and the key of the sentence the page shows; the exception text is
 * for logs only.
 */
enum McpOAuthFailure: string
{
    case Disabled = 'disabled';
    case CallsOff = 'calls_off';
    case AddressNotAllowed = 'address_not_allowed';
    case HttpsRequired = 'https_required';
    case Unreachable = 'unreachable';
    case NoSignIn = 'no_signin';
    case MetadataInvalid = 'metadata_invalid';
    case NoRegistration = 'no_registration';
    case UnsupportedMethod = 'unsupported_method';
    case RegistrationRefused = 'registration_refused';
    case ServerChanged = 'server_changed';
    case Expired = 'expired';
    case ExchangeFailed = 'exchange_failed';
    case Unknown = 'unknown';

    /** Refusals that come from a switch, not from the server. */
    public function isForbidden(): bool
    {
        return self::Disabled === $this || self::CallsOff === $this;
    }
}

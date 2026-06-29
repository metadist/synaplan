<?php

declare(strict_types=1);

namespace App\Service\Client;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parses the request User-Agent into a {@see ClientContext}.
 *
 * The matched token format is a FROZEN contract shared with the native app
 * (synaplan-apps capacitor.config.ts `appendUserAgent` + docs/IDENTIFIERS.md):
 *
 *     Synaplan Mobile V<major>.<minor>[.<patch>]
 *
 * Examples that match: "Synaplan Mobile V4.0", "Synaplan Mobile V4.0.1".
 * Any UA without this token resolves to the web default. Because the UA rides on
 * the WebView for ALL transports (fetch/SSE/WebSocket), the same parser works for
 * every request the app makes.
 */
final class ClientContextResolver
{
    private const UA_PATTERN = '/Synaplan Mobile V(\d+)\.(\d+)(?:\.(\d+))?/';

    public function fromRequest(Request $request): ClientContext
    {
        return $this->fromUserAgent($request->headers->get('User-Agent'));
    }

    public function fromUserAgent(?string $userAgent): ClientContext
    {
        if (null === $userAgent || '' === $userAgent) {
            return ClientContext::web();
        }

        if (1 !== preg_match(self::UA_PATTERN, $userAgent, $matches)) {
            return ClientContext::web();
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = isset($matches[3]) ? (int) $matches[3] : null;

        $version = $major.'.'.$minor.(null !== $patch ? '.'.$patch : '');

        return new ClientContext(
            isMobileApp: true,
            appVersion: $version,
            appVersionMajor: $major,
            appVersionMinor: $minor,
            appVersionPatch: $patch,
        );
    }
}

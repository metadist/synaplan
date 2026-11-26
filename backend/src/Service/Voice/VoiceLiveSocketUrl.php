<?php

declare(strict_types=1);

namespace App\Service\Voice;

use Symfony\Component\HttpFoundation\Request;

/**
 * The public address of the live voice socket for one ticket.
 *
 * A TLS-terminating proxy hands the request on as plain HTTP, and the
 * forwarded scheme is not always trusted. The request alone would then
 * yield `ws://` on an HTTPS site, which a native client refuses to open.
 * An HTTPS `APP_URL` on the same host settles the scheme instead.
 */
final readonly class VoiceLiveSocketUrl
{
    public function __construct(
        private string $publicWsPath,
        private string $appUrl,
    ) {
    }

    public function build(Request $request, string $ticket): string
    {
        $scheme = $this->secure($request) ? 'wss' : 'ws';
        $path = '/'.ltrim($this->publicWsPath, '/');

        return $scheme.'://'.$request->getHttpHost().$path.'?ticket='.rawurlencode($ticket);
    }

    private function secure(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }
        $app = parse_url(trim($this->appUrl));
        if (!is_array($app) || 'https' !== strtolower($app['scheme'] ?? '')) {
            return false;
        }

        return strtolower($app['host'] ?? '') === strtolower($request->getHost());
    }
}

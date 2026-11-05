<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * The page the native shell frames to solve the human check.
 *
 * Turnstile only runs on http(s) documents whose hostname is authorized for
 * the widget. The app's own document is capacitor://localhost (iOS) or
 * https://localhost (Android): the scheme is unsupported, and localhost is
 * not (and must not be) on the production widget. This page is served from
 * the real site, so the widget's hostname matches the one the browser uses.
 *
 * The token is posted to the parent frame. It is never put in the URL.
 * Framing is limited to the app origins; a parent that is not one of them
 * never receives a token.
 */
final readonly class HumanCheckPage
{
    public const PATH = '/api/v1/auth/human-check';

    private const DOCUMENT = <<<'HTML'
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <meta name="referrer" content="no-referrer" />
            <title>Human check</title>
            <style>
              html, body { margin: 0; background: transparent; }
              #challenge { min-height: 65px; }
            </style>
          </head>
          <body>
            <div id="challenge"></div>
            <script nonce="__NONCE__">
              (function () {
                var SOURCE = 'synaplan-human-check'
                var ALLOWED = {
                  'capacitor://localhost': 1,
                  'ionic://localhost': 1,
                  'https://localhost': 1
                }
                var ACTIONS = { login: 1, register: 1, signup: 1, checkout_start: 1, code_signup: 1 }
                var params = new URLSearchParams(location.search)
                var requested = params.get('parent') || ''
                var origins = location.ancestorOrigins
                var actual = origins && origins.length ? origins[0] : ''
                var target = ALLOWED[requested] && window.parent !== window && (!actual || actual === requested)
                  ? requested
                  : ''

                function post(status, extra) {
                  if (!target) return
                  var payload = { source: SOURCE, status: status }
                  if (extra) {
                    for (var key in extra) {
                      if (Object.prototype.hasOwnProperty.call(extra, key)) payload[key] = extra[key]
                    }
                  }
                  window.parent.postMessage(payload, target)
                }

                if (!target) return
                post('ready')

                var action = params.get('action') || ''
                if (!ACTIONS[action]) action = 'login'
                var theme = params.get('theme')
                if (theme !== 'light' && theme !== 'dark') theme = 'auto'
                var lang = params.get('lang') || ''
                if (!/^[a-z]{2}$/.test(lang)) lang = 'auto'

                function fail(code) {
                  post('error', { code: String(code || 'error').slice(0, 32) })
                }

                var scriptReady = new Promise(function (resolve, reject) {
                  var script = document.createElement('script')
                  script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
                  script.async = true
                  script.onload = function () {
                    if (window.turnstile) resolve(window.turnstile)
                    else reject(new Error('script'))
                  }
                  script.onerror = function () { reject(new Error('script')) }
                  document.head.appendChild(script)
                })
                var configReady = fetch('/api/v1/config/runtime', { credentials: 'omit' }).then(function (response) {
                  if (!response.ok) throw new Error('config')
                  return response.json()
                })

                Promise.all([scriptReady, configReady]).then(function (ready) {
                  var api = ready[0]
                  var turnstile = ready[1] && ready[1].turnstile
                  var siteKey = turnstile && typeof turnstile.siteKey === 'string' ? turnstile.siteKey : ''
                  if (!turnstile || turnstile.enabled !== true || siteKey === '') {
                    fail('disabled')
                    return
                  }
                  api.render('#challenge', {
                    sitekey: siteKey,
                    action: action,
                    theme: theme,
                    language: lang,
                    appearance: 'interaction-only',
                    callback: function (token) { post('token', { token: token }) },
                    'error-callback': function (code) { fail(code) },
                    'expired-callback': function () { fail('expired') },
                    'timeout-callback': function () { fail('timeout') },
                    'before-interactive-callback': function () { post('interactive') }
                  })
                }).catch(function (error) {
                  fail(error && error.message ? error.message : 'error')
                })
              })()
            </script>
          </body>
        </html>
        HTML;

    public function response(): Response
    {
        $nonce = bin2hex(random_bytes(16));
        $response = new Response(str_replace('__NONCE__', $nonce, self::DOCUMENT));
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    private function policy(string $nonce): string
    {
        return "default-src 'none'; "
            ."script-src 'nonce-{$nonce}' https://challenges.cloudflare.com; "
            ."style-src 'unsafe-inline'; "
            .'img-src https://challenges.cloudflare.com data:; '
            ."connect-src 'self' https://challenges.cloudflare.com; "
            .'frame-src https://challenges.cloudflare.com; '
            .'worker-src blob:; '
            .'frame-ancestors capacitor://localhost ionic://localhost https://localhost; '
            ."base-uri 'none'; form-action 'none'";
    }
}

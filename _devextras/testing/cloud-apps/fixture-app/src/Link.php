<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Connects a person the way the Nextcloud app does: push the authorization
 * request to Synaplan (code flow with PKCE, naming the person here as
 * external_id), send the browser to Synaplan's authorization endpoint with
 * the reference it got back, and exchange the returned code for the
 * person's tokens with this cloud's client assertion.
 */
final class Link
{
    public static function start(Store $store, string $person): Response
    {
        $person = Store::person($person);
        $state = $store->state();
        if ('' === $state['instance']['id']) {
            throw new FixtureException(409, 'not_registered', 'Register the fixture first (POST /control/register).');
        }
        if ('' === (string) $state['issuer']) {
            Control::refreshKeys($store);
            $state = $store->state();
        }
        $nonce = bin2hex(random_bytes(16));
        $authorize = OAuth::authorizeUrl($state, $person, $nonce);
        $store->update(static function (array $s) use ($nonce, $person, $authorize): array {
            $s['pending'][$nonce] = ['person' => $person, 'verifier' => $authorize['verifier']];

            return $s;
        });

        return Response::redirect($authorize['url']);
    }

    public static function callback(Store $store, string $code, string $nonce, string $error = ''): Response
    {
        $pending = $store->state()['pending'][$nonce] ?? null;
        $store->update(static function (array $s) use ($nonce): array {
            unset($s['pending'][$nonce]);

            return $s;
        });
        if (!is_array($pending) || !is_string($pending['person'] ?? null)) {
            return Response::html(400, 'Not connected', 'This connection was not started here, or it was already used.');
        }
        if ('' !== $error || '' === $code) {
            return Response::html(200, 'Not connected', 'Synaplan answered: '.('' !== $error ? $error : 'no code').'.');
        }
        $answer = OAuth::exchangeCode($store, $pending['person'], $code, (string) $pending['verifier']);
        if (200 !== $answer['status']) {
            return Response::html(502, 'Not connected', 'Synaplan refused the code: '.($answer['body']['error'] ?? 'HTTP '.$answer['status']).'.');
        }

        return Response::html(200, 'Connected', $pending['person'].' is connected to Synaplan. You can close this page.');
    }

    /**
     * Synaplan ended a person's link: forget their tokens.
     *
     * @return array<string, mixed>
     */
    public static function revoked(Store $store, string $person): array
    {
        if ('' === $person) {
            return ['status' => 'ok', 'unlinked' => []];
        }
        OAuth::forget($store, $person);

        return ['status' => 'ok', 'unlinked' => [$person]];
    }
}

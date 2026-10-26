<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Links a person the way the Nextcloud app does: send the browser to
 * Synaplan's confirm page, take the one-time code it returns, and exchange it
 * server to server. The person's key is not kept: the fixture only needs to
 * know that the person is linked, and to which Synaplan account.
 */
final class Link
{
    public static function start(Store $store, string $person): Response
    {
        $person = Store::person($person);
        $state = $store->state();
        if ('' === $state['instance']['id']) {
            throw new FixtureException(409, 'not_registered', 'Register the fixture first (POST /control/register or /control/credential).');
        }
        $nonce = bin2hex(random_bytes(16));
        $store->update(static function (array $s) use ($nonce, $person): array {
            $s['pending'][$nonce] = $person;

            return $s;
        });

        return Response::redirect($state['public_url'].'/connect/platform?'.http_build_query([
            'client' => $state['client'],
            'instance_id' => $state['instance']['id'],
            'external_id' => $person,
            'redirect_uri' => $state['fixture_url'].'/link/callback',
            'state' => $nonce,
        ]));
    }

    public static function callback(Store $store, string $code, string $nonce): Response
    {
        $person = $store->state()['pending'][$nonce] ?? null;
        if (!is_string($person) || '' === $code) {
            return Response::html(400, 'Not linked', 'This link was not started here, or it was already used.');
        }
        $state = $store->update(static function (array $s) use ($nonce): array {
            unset($s['pending'][$nonce]);

            return $s;
        });
        $answer = Synaplan::from($state)->json('POST', '/api/v1/platform-links/exchange', [
            'instance_id' => $state['instance']['id'],
            'instance_secret' => $state['instance']['secret'],
            'code' => $code,
        ]);
        if (200 !== $answer['status']) {
            return Response::html(502, 'Not linked', 'Synaplan refused the code: '.($answer['body']['error'] ?? 'HTTP '.$answer['status']).'.');
        }
        $body = $answer['body'];
        $user = is_array($body['user'] ?? null) ? $body['user'] : [];
        $key = is_array($body['api_key'] ?? null) ? $body['api_key'] : [];
        $store->update(static function (array $s) use ($person, $body, $user, $key): array {
            $s['links'][$person] = [
                'link_id' => (string) ($body['link_id'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'key_id' => $key['id'] ?? null,
                'scopes' => $key['scopes'] ?? [],
                'linked_at' => time(),
            ];

            return $s;
        });

        return Response::html(200, 'Linked', $person.' is linked to '.($user['email'] ?? 'Synaplan').'. You can close this page.');
    }

    /**
     * Synaplan revoked a link: forget it, so this person is no longer linked.
     *
     * @return array<string, mixed>
     */
    public static function revoked(Store $store, string $linkId): array
    {
        $dropped = [];
        $store->update(static function (array $s) use ($linkId, &$dropped): array {
            foreach ($s['links'] as $person => $link) {
                if (is_array($link) && (string) ($link['link_id'] ?? '') === $linkId) {
                    $dropped[] = $person;
                    unset($s['links'][$person]);
                }
            }

            return $s;
        });

        return ['status' => 'ok', 'unlinked' => $dropped];
    }
}

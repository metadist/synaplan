<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * `POST /inbound`: the one address Synaplan calls. A notification is a signed
 * JWT carrying only a type, an id and the person; anything not signed by
 * Synaplan for this instance is refused with 401 before the fixture acts.
 */
final class Inbound
{
    public static function handle(Store $store, string $token): Response
    {
        $state = $store->state();
        $body = Notification::claims($token);
        $kid = Notification::header($token)['kid'] ?? null;
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';
        $id = is_scalar($body['id'] ?? null) ? (string) $body['id'] : '';
        if ('' === $state['instance']['id']) {
            return self::refuse($store, $type, $id, 'not_registered');
        }

        $keys = is_array($state['notify_keys']) ? $state['notify_keys'] : [];
        $outcome = Notification::verify($token, (string) ($state['issuer'] ?? ''), $state['instance']['id'], $keys, time());
        $unknownKey = Notification::BAD_SIGNATURE === $outcome && is_string($kid) && !self::knows($keys, $kid);
        $noIssuer = Notification::WRONG_ISSUER === $outcome && '' === (string) ($state['issuer'] ?? '');
        if ($unknownKey || $noIssuer) {
            // A key the fixture has not seen, or a registration from before the
            // issuer was kept: read Synaplan's current configuration once.
            $keys = Control::refreshKeys($store);
            $outcome = Notification::verify($token, (string) ($store->state()['issuer'] ?? ''), $state['instance']['id'], $keys, time());
        }
        if (Notification::OK !== $outcome) {
            return self::refuse($store, $type, $id, $outcome);
        }

        if (true === $state['switches']['hold_inbound']) {
            $store->log(['type' => $type, 'id' => $id, 'outcome' => 'held']);

            return Response::json(202, ['state' => 'held']);
        }

        // A copy or a folder read is done for the person the notification
        // names, with that person's own key; without one nobody here may act.
        $person = is_string($body['sub'] ?? null) ? $body['sub'] : '';
        $key = Store::personKey($state, $person);
        if (in_array($type, ['delivery', 'pickup'], true) && '' === $key) {
            return self::refuse($store, $type, $id, 'not_linked');
        }
        $synaplan = Synaplan::from($state)->asPerson($key);
        $answer = match ($type) {
            'delivery' => (new Deliveries($store, $synaplan))->handle($id, $person),
            'pickup' => (new Pickups($store, $synaplan))->handle($id, $person),
            'identity_revoked' => Link::revoked($store, $id),
            default => ['status' => 'failed', 'error_code' => 'unsupported'],
        };
        $store->log(['type' => $type, 'id' => $id, 'outcome' => 'handled', 'answer' => $answer]);

        return Response::json(200, $answer);
    }

    private static function refuse(Store $store, string $type, string $id, string $why): Response
    {
        $store->log(['type' => $type, 'id' => $id, 'outcome' => $why]);

        return Response::json(401, ['error' => $why]);
    }

    /**
     * @param array<mixed> $keys
     */
    private static function knows(array $keys, string $kid): bool
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }
}

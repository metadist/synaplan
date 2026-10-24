<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * `POST /inbound`: the one address Synaplan calls. A notification carries
 * only a type and an id; anything not signed by Synaplan for this instance
 * is refused with 401 before the fixture looks at it.
 */
final class Inbound
{
    /**
     * @param array<mixed> $body
     */
    public static function handle(Store $store, array $body): Response
    {
        $state = $store->state();
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';
        $id = is_scalar($body['id'] ?? null) ? (string) $body['id'] : '';
        if ('' === $state['instance']['id']) {
            return self::refuse($store, $type, $id, 'not_registered');
        }

        $keys = is_array($state['notify_keys']) ? $state['notify_keys'] : [];
        $outcome = Notification::verify($body, $state['instance']['id'], $keys, time());
        if (Notification::BAD_SIGNATURE === $outcome && is_string($body['kid'] ?? null) && !self::knows($keys, $body['kid'])) {
            // A key the fixture has not seen: read Synaplan's current keys once.
            $keys = Control::refreshKeys($store);
            $outcome = Notification::verify($body, $state['instance']['id'], $keys, time());
        }
        if (Notification::OK !== $outcome) {
            return self::refuse($store, $type, $id, $outcome);
        }

        if (true === $state['switches']['hold_inbound']) {
            $store->log(['type' => $type, 'id' => $id, 'outcome' => 'held']);

            return Response::json(202, ['state' => 'held']);
        }

        $synaplan = Synaplan::from($state);
        $answer = match ($type) {
            'delivery' => (new Deliveries($store, $synaplan))->handle($id),
            'pickup' => (new Pickups($store, $synaplan))->handle($id),
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

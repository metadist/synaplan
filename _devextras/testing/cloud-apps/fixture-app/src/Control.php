<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * `/control/*`: what the L2 scripts and a person walking a journey use to
 * set the fixture up and look inside it. Development only; the port is
 * published on 127.0.0.1 and nothing here asks for a login.
 */
final class Control
{
    private const CAPABILITIES = ['deliveries', 'pickups', 'watches'];
    private const SCOPE_MODES = ['watched', 'allowed', 'all'];

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param array<mixed> $body
     */
    public function handle(string $method, string $route, array $body, string $raw): Response
    {
        $segments = explode('/', trim($route, '/'));

        return match (true) {
            'GET' === $method && 'state' === $route => Response::json(200, $this->publicState()),
            'POST' === $method && 'reset' === $route => $this->reset(),
            'POST' === $method && 'profile' === $route => $this->profile($body),
            'POST' === $method && 'switch' === $route => $this->switch($body),
            'POST' === $method && 'register' === $route => $this->register($body),
            'GET' === $method && 'registration' === $route => $this->registration(),
            'POST' === $method && 'unlink' === $route => $this->unlink($body),
            'POST' === $method && 'scope' === $route => $this->scope($body),
            'POST' === $method && 'watch-list' === $route => $this->watchList($body),
            'POST' === $method && 'watch-file' === $route => $this->watchFile($body),
            'POST' === $method && 'watch-reconcile' === $route => $this->watchReconcile($body),
            'GET' === $method && 'written' === $route => Response::json(200, ['written' => $this->store->written()]),
            'GET' === $method && 'log' === $route => Response::json(200, ['log' => $this->store->logEntries()]),
            'files' === $segments[0] && count($segments) >= 2 => $this->files($method, $segments[1], implode('/', array_slice($segments, 2)), $raw),
            default => Response::json(404, ['error' => 'not_found', 'message' => 'Unknown control route: '.$method.' /control/'.$route]),
        };
    }

    /**
     * Synaplan's current notify keys, from `instances/self`.
     *
     * @return list<array<string, mixed>>
     */
    public static function refreshKeys(Store $store): array
    {
        $synaplan = Synaplan::from($store->state());
        $configuration = $synaplan->json('GET', '/.well-known/synaplan-configuration');
        $jwks = $synaplan->json('GET', '/.well-known/jwks.json');
        $issuer = is_string($configuration['body']['issuer'] ?? null) ? $configuration['body']['issuer'] : '';
        $keys = [];
        foreach (is_array($jwks['body']['keys'] ?? null) ? $jwks['body']['keys'] : [] as $jwk) {
            if (is_array($jwk) && 'OKP' === ($jwk['kty'] ?? null) && 'Ed25519' === ($jwk['crv'] ?? null) && is_string($jwk['x'] ?? null)) {
                $keys[] = ['publicKey' => 'ed25519:'.$jwk['x'], 'kid' => is_string($jwk['kid'] ?? null) ? $jwk['kid'] : ''];
            }
        }
        if (200 === $configuration['status'] && 200 === $jwks['status'] && '' !== $issuer) {
            $store->update(static function (array $s) use ($keys, $issuer): array {
                $s['notify_keys'] = $keys;
                $s['issuer'] = $issuer;

                return $s;
            });
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicState(): array
    {
        $state = $this->store->state();
        $state['oauth_key'] = is_array($state['oauth_key'] ?? null) ? 'set' : '';
        foreach ($state['links'] as $person => $link) {
            $state['links'][$person] = is_array($link) ? ['linked_at' => $link['linked_at'] ?? null, 'scope' => $link['scope'] ?? ''] : $link;
        }
        unset($state['pending']);

        return $state;
    }

    private function reset(): Response
    {
        $this->store->reset();

        return Response::json(200, $this->publicState());
    }

    /**
     * @param array<mixed> $body
     */
    private function profile(array $body): Response
    {
        $profile = $body['profile'] ?? null;
        if (!in_array($profile, Store::PROFILES, true)) {
            throw new FixtureException(422, 'bad_profile', 'profile is keyed or sso.');
        }
        $this->store->update(static function (array $s) use ($profile): array {
            $s['profile'] = $profile;

            return $s;
        });

        return Response::json(200, $this->publicState());
    }

    /**
     * @param array<mixed> $body
     */
    private function switch(array $body): Response
    {
        $name = $body['name'] ?? null;
        if (!in_array($name, Store::SWITCHES, true)) {
            throw new FixtureException(422, 'bad_switch', 'name is one of '.implode(', ', Store::SWITCHES).'.');
        }
        $on = true === ($body['on'] ?? false);
        $this->store->update(static function (array $s) use ($name, $on): array {
            $s['switches'][$name] = $on;

            return $s;
        });

        return Response::json(200, $this->publicState());
    }

    /**
     * Register with Synaplan as an OAuth2 client (dynamic client
     * registration). With the initial access token Synaplan's administrator
     * handed out (`registration_token`) the client is active at once;
     * otherwise it may wait for an administrator in Synaplan.
     *
     * @param array<mixed> $body
     */
    private function register(array $body): Response
    {
        $client = is_string($body['client'] ?? null) ? $body['client'] : 'nextcloud';
        $this->store->update(static function (array $s) use ($client): array {
            $s['client'] = $client;
            $s['notify_keys'] = [];

            return $s;
        });
        $keys = self::refreshKeys($this->store);
        $token = is_string($body['registration_token'] ?? null) ? $body['registration_token'] : '';
        $answer = OAuth::register($this->store, $token, self::CAPABILITIES);
        if (201 !== $answer['status']) {
            return Response::json(502, ['error' => 'not_registered', 'synaplan' => $answer]);
        }
        $state = $this->store->state();

        return Response::json(200, [
            'client_id' => $state['instance']['id'],
            'status' => $answer['body']['status'] ?? '',
            'reachable' => $answer['body']['reachable'] ?? null,
            'notify_keys' => count($keys),
            'link' => $state['fixture_url'].'/link/start?user=ada',
        ]);
    }

    /**
     * This client's registration as Synaplan has it (RFC 7592).
     */
    private function registration(): Response
    {
        $answer = OAuth::configuration($this->store);

        return Response::json($answer['status'] > 0 ? $answer['status'] : 502, $answer['body']);
    }

    /**
     * The person leaves: revoke their grant at Synaplan, which ends the link.
     *
     * @param array<mixed> $body
     */
    private function unlink(array $body): Response
    {
        $person = Store::person(is_string($body['person'] ?? null) ? $body['person'] : '');

        return Response::json(200, ['revoked' => OAuth::revoke($this->store, $person)]);
    }

    /**
     * What a person lets Synaplan read: kept here (the app enforces it) and
     * reported to Synaplan (which shows it and refuses early).
     *
     * @param array<mixed> $body
     */
    private function scope(array $body): Response
    {
        $person = Store::person(is_string($body['person'] ?? null) ? $body['person'] : '');
        $mode = $body['mode'] ?? null;
        if (!in_array($mode, self::SCOPE_MODES, true)) {
            throw new FixtureException(422, 'bad_scope', 'mode is watched, allowed or all.');
        }
        $folders = [];
        foreach (is_array($body['folders'] ?? null) ? $body['folders'] : [] as $folder) {
            if (is_string($folder) && '' !== Store::cleanPath($folder)) {
                $folders[] = Store::cleanPath($folder);
            }
        }
        $state = $this->store->update(static function (array $s) use ($person, $mode, $folders): array {
            $s['scopes'][$person] = ['mode' => $mode, 'folders' => $folders];

            return $s;
        });
        $key = OAuth::accessToken($this->store, $person);
        if ('' === $key) {
            return Response::json(200, ['scope' => $state['scopes'][$person], 'reported' => ['error' => 'not_linked']]);
        }
        $reported = Synaplan::from($state)->asPerson($key)->json('PUT', '/api/v1/platform-links/read-scope', [
            'external_id' => $person,
            'mode' => $mode,
            'folders' => array_map(static fn (string $path): array => [
                'id' => Profile::remoteId((string) $state['profile'], $person, $path),
                'path' => $path,
            ], $folders),
        ]);

        return Response::json(200, ['scope' => $state['scopes'][$person], 'reported' => 200 === $reported['status'] ? true : $reported]);
    }

    private function files(string $method, string $person, string $path, string $raw): Response
    {
        return match ($method) {
            'GET' => Response::json(200, ['person' => $person, 'files' => $this->store->files($person, $path)]),
            'PUT' => $this->putFile($person, $path, $raw),
            'DELETE' => $this->store->delete($person, $path)
                ? Response::json(200, ['deleted' => $path])
                : Response::json(404, ['error' => 'not_found']),
            default => Response::json(405, ['error' => 'method_not_allowed']),
        };
    }

    private function putFile(string $person, string $path, string $raw): Response
    {
        if ('' === Store::cleanPath($path)) {
            throw new FixtureException(422, 'bad_path', 'PUT /control/files/{person}/{path} needs a file path.');
        }
        $this->store->put($person, $path, $raw);
        $profile = (string) $this->store->state()['profile'];

        return Response::json(201, [
            'path' => Store::cleanPath($path),
            'id' => Profile::remoteId($profile, $person, Store::cleanPath($path)),
            'version' => Profile::version($profile, $raw),
            'size' => strlen($raw),
        ]);
    }

    /** @param array<mixed> $body */
    private function watchList(array $body): Response
    {
        $person = Store::person(is_string($body['person'] ?? null) ? $body['person'] : '');
        $key = OAuth::accessToken($this->store, $person);
        if ('' === $key) {
            return Response::json(409, ['error' => 'not_linked']);
        }
        $answer = (new Watches(Synaplan::from($this->store->state())))->list($key);

        return Response::json($answer['status'] > 0 ? $answer['status'] : 502, $answer['body']);
    }

    /** @param array<mixed> $body */
    private function watchFile(array $body): Response
    {
        $apiKey = OAuth::accessToken($this->store, Store::person(is_string($body['person'] ?? null) ? $body['person'] : ''));
        $watchId = $body['watch_id'] ?? null;
        $id = $body['id'] ?? null;
        $version = $body['version'] ?? null;
        if ('' === $apiKey || !is_int($watchId) || !is_string($id) || !is_string($version)) {
            throw new FixtureException(422, 'bad_watch_file', 'a connected person, watch_id, id and version are required.');
        }
        $name = is_string($body['name'] ?? null) ? $body['name'] : 'file.bin';
        $bytes = is_string($body['content'] ?? null) ? $body['content'] : '';
        $answer = (new Watches(Synaplan::from($this->store->state())))->upload($apiKey, $watchId, [
            'id' => $id,
            'version' => $version,
            'name' => $name,
            'event' => is_string($body['event'] ?? null) ? $body['event'] : 'created',
        ], $bytes, $name);

        return Response::json($answer['status'] > 0 ? $answer['status'] : 502, $answer['body']);
    }

    /** @param array<mixed> $body */
    private function watchReconcile(array $body): Response
    {
        $apiKey = OAuth::accessToken($this->store, Store::person(is_string($body['person'] ?? null) ? $body['person'] : ''));
        $watchId = $body['watch_id'] ?? null;
        $items = $body['items'] ?? null;
        if ('' === $apiKey || !is_int($watchId) || !is_array($items)) {
            throw new FixtureException(422, 'bad_watch_reconcile', 'a connected person, watch_id and items are required.');
        }
        $rows = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['version'] ?? null)) {
                $rows[] = ['id' => $item['id'], 'version' => $item['version']];
            }
        }
        $answer = (new Watches(Synaplan::from($this->store->state())))->reconcile($apiKey, $watchId, $rows);

        return Response::json($answer['status'] > 0 ? $answer['status'] : 502, $answer['body']);
    }
}

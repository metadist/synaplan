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
    private const CAPABILITIES = ['deliveries', 'pickups'];
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
            'POST' === $method && 'credential' === $route => $this->credential($body),
            'POST' === $method && 'scope' === $route => $this->scope($body),
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
        $self = Synaplan::from($store->state())->json('GET', '/api/v1/platform-links/instances/self');
        $keys = is_array($self['body']['notify_keys'] ?? null) ? array_values(array_filter($self['body']['notify_keys'], 'is_array')) : [];
        if (200 === $self['status']) {
            $store->update(static function (array $s) use ($keys): array {
                $s['notify_keys'] = $keys;

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
        $state['instance']['secret'] = '' !== $state['instance']['secret'] ? 'set' : '';
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
     * Register with Synaplan as a new instance. With an administrator's API
     * key (`admin_key`, used for this call only) the instance is active at
     * once; without one it waits for an administrator in Synaplan.
     *
     * @param array<mixed> $body
     */
    private function register(array $body): Response
    {
        $client = is_string($body['client'] ?? null) ? $body['client'] : 'nextcloud';
        $state = $this->store->state();
        $headers = is_string($body['admin_key'] ?? null) && '' !== $body['admin_key'] ? ['X-API-Key' => $body['admin_key']] : [];
        $answer = (new Synaplan($state['synaplan_url'], '', ''))->json('POST', '/api/v1/platform-links/instances', [
            'client' => $client,
            'host' => (string) parse_url($state['fixture_url'], PHP_URL_HOST).(null !== parse_url($state['fixture_url'], PHP_URL_PORT) ? ':'.parse_url($state['fixture_url'], PHP_URL_PORT) : ''),
            'redirect_uris' => [$state['fixture_url'].'/link/callback'],
        ], $headers);
        if (201 !== $answer['status']) {
            return Response::json(502, ['error' => 'not_registered', 'synaplan' => $answer]);
        }

        return $this->remember(
            (string) ($answer['body']['instance_id'] ?? ''),
            (string) ($answer['body']['instance_secret'] ?? ''),
            $client,
            (string) ($answer['body']['status'] ?? ''),
        );
    }

    /**
     * Use an instance an administrator registered elsewhere.
     *
     * @param array<mixed> $body
     */
    private function credential(array $body): Response
    {
        $id = $body['instance_id'] ?? null;
        $secret = $body['instance_secret'] ?? null;
        if (!is_string($id) || !is_string($secret) || '' === $id || '' === $secret) {
            throw new FixtureException(422, 'bad_credential', 'instance_id and instance_secret are required.');
        }

        return $this->remember($id, $secret, is_string($body['client'] ?? null) ? $body['client'] : 'nextcloud', '');
    }

    /**
     * Store the credential, tell Synaplan where to call and what this app
     * does, and read the keys Synaplan signs with.
     */
    private function remember(string $id, string $secret, string $client, string $status): Response
    {
        $state = $this->store->update(static function (array $s) use ($id, $secret, $client): array {
            $s['instance'] = ['id' => $id, 'secret' => $secret];
            $s['client'] = $client;
            $s['notify_keys'] = [];

            return $s;
        });
        $announced = Synaplan::from($state)->json('PATCH', '/api/v1/platform-links/instances/self', [
            'delivery_url' => $state['inbound_url'],
            'app_capabilities' => self::CAPABILITIES,
        ]);
        $keys = self::refreshKeys($this->store);

        return Response::json(200, [
            'instance_id' => $id,
            'status' => $status,
            'announced' => 200 === $announced['status'] ? true : $announced,
            'notify_keys' => count($keys),
            'link' => $state['fixture_url'].'/link/start?user=ada',
        ]);
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
        $reported = Synaplan::from($state)->json('PUT', '/api/v1/platform-links/read-scope', [
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
}

<?php

declare(strict_types=1);

/**
 * The reference client of the cloud file sharing plan (E-9): plays the app
 * side of every protocol, before and beside the real apps. Run it with PHP's
 * built-in server; see README.md.
 *
 *   php -S 0.0.0.0:8099 _devextras/testing/cloud-apps/fixture-app/index.php
 */

use CloudFixture\Control;
use CloudFixture\FixtureException;
use CloudFixture\Inbound;
use CloudFixture\Link;
use CloudFixture\Response;
use CloudFixture\Store;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'CloudFixture\\')) {
        require __DIR__.'/src/'.substr($class, strlen('CloudFixture\\')).'.php';
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$raw = (string) file_get_contents('php://input');
$decoded = json_decode('' !== $raw ? $raw : '{}', true);
$body = is_array($decoded) ? $decoded : [];

try {
    $store = Store::fromEnvironment();
    $response = match (true) {
        'POST' === $method && '/inbound' === $path => Inbound::handle($store, $body),
        'GET' === $method && '/link/start' === $path => Link::start($store, (string) ($_GET['user'] ?? 'ada')),
        'GET' === $method && '/link/callback' === $path => Link::callback($store, (string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? '')),
        str_starts_with($path, '/control/') => (new Control($store))->handle($method, substr($path, strlen('/control/')), $body, $raw),
        'GET' === $method && str_starts_with($path, '/files/') => (static function () use ($store, $path): Response {
            [$person, $file] = array_pad(explode('/', substr($path, strlen('/files/')), 2), 2, '');
            $bytes = $store->read($person, $file);

            return null === $bytes ? Response::json(404, ['error' => 'not_found']) : Response::file($bytes, basename($file));
        })(),
        'GET' === $method && '/' === $path => Response::json(200, [
            'app' => 'cloud-fixture',
            'profile' => $store->state()['profile'],
            'registered' => '' !== $store->state()['instance']['id'],
        ]),
        default => Response::json(404, ['error' => 'not_found']),
    };
} catch (FixtureException $e) {
    $response = Response::json($e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()]);
}

$response->send();

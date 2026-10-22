<?php

declare(strict_types=1);

/**
 * Mint and revoke the temporary benchmark key. The secret is written to a
 * file and never printed. Dev stack only: the seeded admin from the docs.
 *
 *   php mint_key.php mint <key-file> <cookie-file>
 *   php mint_key.php revoke <cookie-file>
 */

const KEY_NAME = 'perf-runtime';
const BASE = 'http://127.0.0.1';

$command = $argv[1] ?? '';
if ('mint' === $command) {
    exit(mint($argv[2] ?? '', $argv[3] ?? ''));
}
if ('revoke' === $command) {
    exit(revoke($argv[2] ?? ''));
}

fwrite(STDERR, "usage: mint_key.php mint <key-file> <cookie-file> | revoke <cookie-file>\n");
exit(2);

function mint(string $keyFile, string $cookieFile): int
{
    if ('' === $keyFile || '' === $cookieFile) {
        fwrite(STDERR, "mint needs a key file and a cookie file\n");

        return 2;
    }
    $password = getenv('SYNAPLAN_PERF_ADMIN_PASSWORD');
    if (!is_string($password) || '' === $password) {
        fwrite(STDERR, "SYNAPLAN_PERF_ADMIN_PASSWORD is required\n");

        return 2;
    }
    $email = getenv('SYNAPLAN_PERF_ADMIN_EMAIL');
    $email = is_string($email) && '' !== $email ? $email : 'admin@synaplan.com';

    [$status, $raw, $cookies] = request('POST', BASE.'/api/v1/auth/login', json_encode([
        'email' => $email,
        'password' => $password,
        'recaptchaToken' => '',
    ], JSON_THROW_ON_ERROR), '');
    if (200 !== $status) {
        fwrite(STDERR, 'login failed: HTTP '.$status.' '.errorMessage($raw)."\n");

        return 1;
    }
    $login = json_decode($raw, true);
    $userId = is_array($login) ? ($login['user']['id'] ?? null) : null;
    if (!is_int($userId) && !is_numeric($userId)) {
        fwrite(STDERR, "login response had no user id\n");

        return 1;
    }
    $cookie = cookieHeader($cookies);
    if ('' === $cookie) {
        fwrite(STDERR, "login response had no auth cookie\n");

        return 1;
    }
    if (!writePrivate($cookieFile, $cookie)) {
        return 1;
    }

    if (!sweep($cookie)) {
        return 1;
    }

    [$status, $raw] = request('POST', BASE.'/api/v1/apikeys', json_encode([
        'name' => KEY_NAME,
        'scopes' => ['desktop:messages'],
    ], JSON_THROW_ON_ERROR), $cookie);
    if (201 !== $status) {
        fwrite(STDERR, 'create key failed: HTTP '.$status.' '.errorMessage($raw)."\n");

        return 1;
    }
    $created = json_decode($raw, true);
    $key = is_array($created) ? ($created['api_key']['key'] ?? null) : null;
    $id = is_array($created) ? ($created['api_key']['id'] ?? null) : null;
    if (!is_string($key) || '' === $key || !is_numeric($id)) {
        fwrite(STDERR, "create key response had no key\n");

        return 1;
    }
    if (!writePrivate($keyFile, json_encode(['api_key' => ['id' => (int) $id, 'key' => $key]], JSON_THROW_ON_ERROR))) {
        return 1;
    }
    echo 'key_id='.(int) $id."\n";
    echo 'user_id='.(int) $userId."\n";

    return 0;
}

function revoke(string $cookieFile): int
{
    if (!is_file($cookieFile)) {
        fwrite(STDERR, "cookie file missing; cannot revoke\n");

        return 1;
    }
    $cookie = (string) file_get_contents($cookieFile);
    if ('' === $cookie) {
        fwrite(STDERR, "cookie file empty; cannot revoke\n");

        return 1;
    }

    $swept = sweep($cookie);
    [$status] = request('POST', BASE.'/api/v1/auth/logout', '', $cookie);
    if (200 !== $status) {
        fwrite(STDERR, 'logout failed: HTTP '.$status."\n");

        return 1;
    }

    return $swept ? 0 : 1;
}

function sweep(string $cookie): bool
{
    [$status, $raw] = request('GET', BASE.'/api/v1/apikeys', null, $cookie);
    if (200 !== $status) {
        fwrite(STDERR, 'list keys failed: HTTP '.$status.' '.errorMessage($raw)."\n");

        return false;
    }
    $listed = json_decode($raw, true);
    $keys = is_array($listed) ? ($listed['api_keys'] ?? []) : [];
    if (!is_array($keys)) {
        fwrite(STDERR, "list keys response was not a list\n");

        return false;
    }
    foreach ($keys as $key) {
        if (!is_array($key) || KEY_NAME !== ($key['name'] ?? '') || !is_numeric($key['id'] ?? null)) {
            continue;
        }
        [$deleteStatus, $deleteBody] = request('DELETE', BASE.'/api/v1/apikeys/'.(int) $key['id'], '', $cookie);
        if (200 !== $deleteStatus) {
            fwrite(STDERR, 'revoke failed: HTTP '.$deleteStatus.' '.errorMessage($deleteBody)."\n");

            return false;
        }
    }

    return true;
}

/**
 * @return array{0: int, 1: string, 2: list<string>}
 */
function request(string $method, string $url, ?string $body, string $cookie): array
{
    $header = "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ('' !== $cookie) {
        $header .= 'Cookie: '.$cookie."\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $header,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    $cookies = [];
    foreach ($http_response_header ?? [] as $line) {
        if (1 === preg_match('#^HTTP/\S+\s+(\d+)#', $line, $match)) {
            $status = (int) $match[1];
        }
        if (str_starts_with(strtolower($line), 'set-cookie:')) {
            $cookies[] = trim(substr($line, strlen('Set-Cookie:')));
        }
    }

    return [$status, false === $raw ? '' : $raw, $cookies];
}

/**
 * @param list<string> $setCookies
 */
function cookieHeader(array $setCookies): string
{
    $pairs = [];
    foreach ($setCookies as $setCookie) {
        $pair = trim(explode(';', $setCookie, 2)[0]);
        if (str_contains($pair, '=')) {
            $pairs[] = $pair;
        }
    }

    return implode('; ', $pairs);
}

function errorMessage(string $raw): string
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }
    $message = $decoded['error'] ?? $decoded['message'] ?? '';

    return is_string($message) ? $message : '';
}

function writePrivate(string $path, string $contents): bool
{
    if (false === file_put_contents($path, $contents)) {
        fwrite(STDERR, 'could not write '.$path."\n");

        return false;
    }
    chmod($path, 0600);

    return true;
}

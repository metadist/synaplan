<?php

// Review spike (2026-10-18) — not product code.
//
// Mints the call-token test vector of 02_contracts.md §3 with the firebase/php-jwt
// library Synaplan already ships, and verifies a vector the same way a PHP service
// would. Run inside the backend image (run.sh does that):
//
//   php mint_token.php mint                # print a fresh vector (new key pair) as JSON
//   php mint_token.php verify vector.json  # verify the published vector + tamper cases
//
// Every mint uses a new P-256 key pair and publishes only its public half;
// PSEUDONYM_KEY is a TEST VALUE, published on purpose.

declare(strict_types=1);

require '/var/www/backend/vendor/autoload.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

const PSEUDONYM_KEY = '32560b53bf7bdecf001cf213f490be627bdcea7845acc16896b4cc15bc858923';
const TOKEN_TYPE = 'synaplan-call+jwt';
const NOW = 1792130030;
const LEEWAY = 30;

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** @return array{kty: string, crv: string, x: string, y: string} */
function publicJwk(\OpenSSLAsymmetricKey $key): array
{
    $ec = openssl_pkey_get_details($key)['ec'];

    return ['kty' => 'EC', 'crv' => 'P-256', 'x' => b64url($ec['x']), 'y' => b64url($ec['y'])];
}

/** RFC 7638 thumbprint: SHA-256 over the required members in lexicographic order. */
function thumbprint(array $jwk): string
{
    return b64url(hash('sha256', sprintf('{"crv":"%s","kty":"%s","x":"%s","y":"%s"}', $jwk['crv'], $jwk['kty'], $jwk['x'], $jwk['y']), true));
}

function pseudonym(string $scope, int $userId): string
{
    return 'u_'.substr(hash_hmac('sha256', $scope."\n".'user:'.$userId, PSEUDONYM_KEY), 0, 32);
}

/**
 * @param array<string, mixed> $vector
 *
 * @return string null when the token passes, else the reason it was refused
 */
function refusal(array $vector, string $token, string $audience, string $method, string $url, string $body, int $now): ?string
{
    JWT::$timestamp = $now;
    JWT::$leeway = LEEWAY;
    try {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 512, JSON_THROW_ON_ERROR);
        if (TOKEN_TYPE !== ($header['typ'] ?? null)) {
            return 'wrong typ';
        }
        $claims = (array) JWT::decode($token, JWK::parseKeySet($vector['jwks'], 'ES256'));
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
    if (($claims['aud'] ?? null) !== $audience) {
        return 'wrong audience';
    }
    if (($claims['htm'] ?? null) !== $method || ($claims['htu'] ?? null) !== $url) {
        return 'wrong method or URL';
    }
    if (($claims['bh'] ?? null) !== b64url(hash('sha256', $body, true))) {
        return 'body hash mismatch';
    }

    return null;
}

$mode = $argv[1] ?? 'mint';

if ('mint' === $mode) {
    $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $jwk = publicJwk($key);
    $kid = thumbprint($jwk);
    $body = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"word_stats","arguments":{"text":"hello world"}}}';
    $url = 'http://word-tools:8000/mcp';
    $claims = [
        'iss' => 'http://localhost:8000',
        'aud' => $url,
        'sub' => pseudonym('mcp:12', 2),
        'iat' => 1792130000,
        'exp' => 1792130060,
        'jti' => '5d7c0f0e-2b8e-4c39-9a51-0d6f3c1e7a42',
        'htm' => 'POST',
        'htu' => $url,
        'bh' => b64url(hash('sha256', $body, true)),
    ];
    $token = JWT::encode($claims, $key, 'ES256', $kid, ['typ' => TOKEN_TYPE]);
    echo json_encode([
        'jwks' => ['keys' => [$jwk + ['kid' => $kid, 'alg' => 'ES256', 'use' => 'sig']]],
        'pseudonym' => ['key' => PSEUDONYM_KEY, 'scope' => 'mcp:12', 'userId' => 2, 'sub' => $claims['sub']],
        'request' => ['method' => 'POST', 'url' => $url, 'body' => $body],
        'claims' => $claims,
        'token' => $token,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$vector = json_decode((string) file_get_contents($argv[2] ?? ''), true, 512, JSON_THROW_ON_ERROR);
['method' => $method, 'url' => $url, 'body' => $body] = $vector['request'];
$token = $vector['token'];
[$h, $p, $s] = explode('.', $token);
$forged = $h.'.'.b64url(str_replace('"sub":"'.$vector['claims']['sub'].'"', '"sub":"u_00000000000000000000000000000000"', JWT::urlsafeB64Decode($p))).'.'.$s;

$cases = [
    'valid' => [$token, $url, $method, $url, $body, NOW, true],
    'changed body' => [$token, $url, $method, $url, str_replace('hello', 'HELLO', $body), NOW, false],
    'other audience' => [$token, 'http://other-tool:8000/mcp', $method, $url, $body, NOW, false],
    'other method' => [$token, $url, 'DELETE', $url, $body, NOW, false],
    'forged subject' => [$forged, $url, $method, $url, $body, NOW, false],
    'expired (now + 170 s)' => [$token, $url, $method, $url, $body, NOW + 170, false],
];
$failed = 0;
foreach ($cases as $label => [$t, $aud, $m, $u, $b, $now, $expectOk]) {
    $reason = refusal($vector, $t, $aud, $m, $u, $b, $now);
    $ok = (null === $reason) === $expectOk;
    $failed += $ok ? 0 : 1;
    printf("php %-24s %s%s\n", $label.':', $ok ? 'as expected' : 'UNEXPECTED', null === $reason ? ' (accepted)' : ' (refused: '.$reason.')');
}
exit($failed > 0 ? 1 : 0);

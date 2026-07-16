<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AppleClientSecretGenerator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class AppleClientSecretGeneratorTest extends TestCase
{
    private const TEAM_ID = 'X9GM4T2MQG';
    private const KEY_ID = 'S6YQ877G37';
    private const CLIENT_ID = 'com.synaplan.app.web';

    public function testGeneratesSignedEs256ClientSecretWithAppleClaims(): void
    {
        [$privatePem, $publicPem] = $this->generateEcKeyPair();

        $generator = new AppleClientSecretGenerator(self::TEAM_ID, self::KEY_ID, self::CLIENT_ID, $privatePem);
        self::assertTrue($generator->isConfigured());

        $jwt = $generator->generate();

        // Header carries the ES256 alg and the key id.
        $header = $this->decodeSegment(explode('.', $jwt)[0]);
        self::assertSame('ES256', $header['alg'] ?? null);
        self::assertSame(self::KEY_ID, $header['kid'] ?? null);

        // Payload verifies against the public key and carries Apple's claims.
        $decoded = (array) JWT::decode($jwt, new Key($publicPem, 'ES256'));
        self::assertSame(self::TEAM_ID, $decoded['iss'] ?? null);
        self::assertSame(self::CLIENT_ID, $decoded['sub'] ?? null);
        self::assertSame('https://appleid.apple.com', $decoded['aud'] ?? null);
        self::assertIsInt($decoded['exp'] ?? null);
        self::assertGreaterThan(time(), $decoded['exp']);
    }

    public function testIsConfiguredIsFalseWhenPrivateKeyMissing(): void
    {
        $generator = new AppleClientSecretGenerator(self::TEAM_ID, self::KEY_ID, self::CLIENT_ID, '');

        self::assertFalse($generator->isConfigured());
        $this->expectException(\RuntimeException::class);
        $generator->generate();
    }

    public function testAcceptsPrivateKeyWithEscapedNewlines(): void
    {
        [$privatePem, $publicPem] = $this->generateEcKeyPair();
        $escaped = str_replace("\n", '\n', $privatePem);

        $generator = new AppleClientSecretGenerator(self::TEAM_ID, self::KEY_ID, self::CLIENT_ID, $escaped);
        $jwt = $generator->generate();

        $decoded = (array) JWT::decode($jwt, new Key($publicPem, 'ES256'));
        self::assertSame(self::TEAM_ID, $decoded['iss'] ?? null);
    }

    /**
     * @return array{0: string, 1: string} [privatePem, publicPem]
     */
    private function generateEcKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if (false === $res) {
            self::fail('Unable to generate EC key pair for test');
        }

        $privatePem = '';
        if (!openssl_pkey_export($res, $privatePem)) {
            self::fail('Unable to export EC private key for test');
        }

        $details = openssl_pkey_get_details($res);
        if (false === $details || !isset($details['key']) || !is_string($details['key'])) {
            self::fail('Unable to read EC public key for test');
        }

        return [$privatePem, $details['key']];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSegment(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        if (false === $json) {
            self::fail('Invalid JWT segment');
        }

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AppleIdentityTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AppleIdentityTokenVerifierTest extends TestCase
{
    private const WEB_CLIENT_ID = 'com.synaplan.app.web';
    private const APP_BUNDLE_ID = 'com.synaplan.app';
    private const KID = 'test-apple-kid';

    private string $privatePem;
    /** @var array{keys: list<array<string, string>>} */
    private array $jwks;

    protected function setUp(): void
    {
        [$this->privatePem, $this->jwks] = $this->generateRsaKeyAndJwks();
    }

    public function testVerifiesWebAudienceTokenAndExtractsClaims(): void
    {
        $token = $this->signToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::WEB_CLIENT_ID,
            'sub' => '001999.abcdef',
            'email' => 'user@example.com',
            'email_verified' => true,
            'is_private_email' => false,
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ]);

        $claims = $this->verifier()->verify($token);

        self::assertSame('001999.abcdef', $claims['sub']);
        self::assertSame('user@example.com', $claims['email']);
        self::assertTrue($claims['emailVerified']);
        self::assertFalse($claims['isPrivateEmail']);
    }

    public function testVerifiesNativeBundleAudienceAndAppleStringBooleans(): void
    {
        // Apple frequently serialises the booleans as strings.
        $token = $this->signToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::APP_BUNDLE_ID,
            'sub' => '001999.native',
            'email' => 'relay@privaterelay.appleid.com',
            'email_verified' => 'true',
            'is_private_email' => 'true',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ]);

        $claims = $this->verifier()->verify($token);

        self::assertSame('001999.native', $claims['sub']);
        self::assertTrue($claims['emailVerified']);
        self::assertTrue($claims['isPrivateEmail']);
    }

    public function testRejectsUnexpectedAudience(): void
    {
        $token = $this->signToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.someone.else',
            'sub' => '001999.abcdef',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->verifier()->verify($token);
    }

    public function testRejectsUnexpectedIssuer(): void
    {
        $token = $this->signToken([
            'iss' => 'https://evil.example.com',
            'aud' => self::WEB_CLIENT_ID,
            'sub' => '001999.abcdef',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->verifier()->verify($token);
    }

    public function testRejectsExpiredToken(): void
    {
        $token = $this->signToken([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::WEB_CLIENT_ID,
            'sub' => '001999.abcdef',
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->verifier()->verify($token);
    }

    private function verifier(): AppleIdentityTokenVerifier
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode($this->jwks)));

        return new AppleIdentityTokenVerifier(
            $http,
            new ArrayAdapter(),
            new NullLogger(),
            self::WEB_CLIENT_ID,
            self::APP_BUNDLE_ID,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signToken(array $claims): string
    {
        return JWT::encode($claims, $this->privatePem, 'RS256', self::KID);
    }

    /**
     * @return array{0: string, 1: array{keys: list<array<string, string>>}}
     */
    private function generateRsaKeyAndJwks(): array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if (false === $res) {
            self::fail('Unable to generate RSA key for test');
        }

        $privatePem = '';
        if (!openssl_pkey_export($res, $privatePem)) {
            self::fail('Unable to export RSA private key for test');
        }

        $details = openssl_pkey_get_details($res);
        if (false === $details || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            self::fail('Unable to read RSA modulus/exponent for test');
        }

        /** @var non-empty-string $n */
        $n = $details['rsa']['n'];
        /** @var non-empty-string $e */
        $e = $details['rsa']['e'];

        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => self::KID,
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $this->base64Url($n),
                    'e' => $this->base64Url($e),
                ],
            ],
        ];

        return [$privatePem, $jwks];
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

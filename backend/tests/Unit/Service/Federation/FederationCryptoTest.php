<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Federation;

use App\Service\Federation\FederationException;
use App\Service\Federation\FederationInviteLink;
use App\Service\Federation\FederationSigner;
use App\Service\Federation\FederationUrlGuard;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class FederationCryptoTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        if (!FederationSigner::available()) {
            self::markTestSkipped('ext-sodium is not loaded');
        }
        $signer = new FederationSigner();
        $keys = $signer->generate();
        $signed = $signer->signBody([
            'protocol' => 0,
            'action' => 'pause',
            'from' => 'contoso.example',
            'nonce' => 'abcdabcdabcdabcd',
            'issuedAt' => '2026-09-29T21:00:00Z',
        ], $keys['secretKey']);

        $signer->verifyBody($signed, $keys['publicKey']);
        $this->assertSame(16, strlen($signer->fingerprint($keys['publicKey'])));
    }

    public function testATamperedBodyFailsVerification(): void
    {
        if (!FederationSigner::available()) {
            self::markTestSkipped('ext-sodium is not loaded');
        }
        $signer = new FederationSigner();
        $keys = $signer->generate();
        $signed = $signer->signBody(['protocol' => 0, 'action' => 'end', 'from' => 'a.example'], $keys['secretKey']);
        $signed['action'] = 'pause';

        $this->expectException(FederationException::class);
        $signer->verifyBody($signed, $keys['publicKey']);
    }

    public function testCanonicalJsonIgnoresKeyOrder(): void
    {
        $signer = new FederationSigner();
        $left = $signer->canonical(['b' => 1, 'a' => ['d' => 2, 'c' => 3]]);
        $right = $signer->canonical(['a' => ['c' => 3, 'd' => 2], 'b' => 1]);

        $this->assertSame($left, $right);
    }

    public function testPrivateAddressesCannotBeOpenedOrCalled(): void
    {
        $guard = new FederationUrlGuard(new SsrfGuard(), false);

        $this->assertFalse($guard->isReachableAppUrl('http://localhost:8000'));
        $this->assertFalse($guard->isReachableAppUrl('https://10.1.2.3'));
        $this->assertTrue($guard->isReachableAppUrl('https://contoso.example'));

        try {
            $guard->assertOutbound('https://127.0.0.1/api');
            $this->fail('private target was allowed');
        } catch (FederationException $e) {
            $this->assertSame('bad_url', $e->errorCode);
        }
    }

    public function testAllowLocalLetsADevServerConnect(): void
    {
        $guard = new FederationUrlGuard(new SsrfGuard(), true);

        $this->assertTrue($guard->isReachableAppUrl('http://localhost:8000'));
        $guard->assertOutbound('http://127.0.0.1:8000/api/v1/federation/connect');
        $this->addToAssertionCount(1);
    }

    public function testInviteAddressMustBeTheApiPath(): void
    {
        $parsed = FederationInviteLink::parse('https://contoso.example/api/v1/federation/invites/0123456789abcdef0123456789abcdef');

        $this->assertSame('https://contoso.example', $parsed['origin']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $parsed['token']);

        $this->expectException(FederationException::class);
        FederationInviteLink::parse('https://contoso.example/partners/join/0123456789abcdef0123456789abcdef');
    }
}

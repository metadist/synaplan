<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\PlatformLink\NotificationToken;
use PHPUnit\Framework\TestCase;

/**
 * The same file is checked by every cloud app, so a canonical-JSON difference
 * fails here instead of in production.
 */
final class NotifyVectorTest extends TestCase
{
    public function testTheSharedVectorsMatchTheVerifier(): void
    {
        $path = dirname(__DIR__, 3).'/Fixtures/cloud-apps-contract/notify-vectors.json';
        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc);

        foreach ($doc['vectors'] as $vector) {
            self::assertSame(
                $vector['expect'],
                NotificationToken::verdict($vector['token'], (string) $doc['issuer'], (string) $doc['receiver_instance_id'], [(string) $doc['public_key']], (int) $doc['now']),
                $vector['name'],
            );
        }
    }

    public function testATokenCarriesThePersonAndVerifiesWithItsKey(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $secret = 'ed25519:'.$b64(sodium_crypto_sign_secretkey($pair));
        $public = 'ed25519:'.$b64(sodium_crypto_sign_publickey($pair));
        $token = NotificationToken::encode(NotificationToken::claimsFor('https://synaplan.example', 'pickup', '15', 'oidc-cloud', 'ada', 1_700_000_000), $secret, 'k1');

        self::assertSame(NotificationToken::OK, NotificationToken::verdict($token, 'https://synaplan.example', 'oidc-cloud', [$public], 1_700_000_100));
        self::assertSame(NotificationToken::STALE, NotificationToken::verdict($token, 'https://synaplan.example', 'oidc-cloud', [$public], 1_700_000_400));
        $exp = (int) (NotificationToken::verifiedClaims($token, [$public])['exp'] ?? 0);
        self::assertSame(NotificationToken::OK, NotificationToken::verdict($token, 'https://synaplan.example', 'oidc-cloud', [$public], $exp - 1));
        self::assertSame(NotificationToken::STALE, NotificationToken::verdict($token, 'https://synaplan.example', 'oidc-cloud', [$public], $exp), 'exp is exclusive (RFC 7519 4.1.4)');
        self::assertSame(NotificationToken::WRONG_ISSUER, NotificationToken::verdict($token, 'https://other.example', 'oidc-cloud', [$public], 1_700_000_100));
        $claims = NotificationToken::verifiedClaims($token, [$public]);
        self::assertSame(['oidc-cloud', 'ada', 'pickup', '15'], [$claims['aud'] ?? null, $claims['sub'] ?? null, $claims['type'] ?? null, $claims['id'] ?? null]);
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
        self::assertSame(['alg' => 'EdDSA', 'typ' => NotificationToken::TYPE, 'kid' => 'k1'], $header);
    }
}

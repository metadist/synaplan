<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Auth\NativeAuthHandoffService;
use App\Service\OAuth\OAuthStateService;
use App\Service\Security\PurposeKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PurposeKeyTest extends TestCase
{
    private const SECRET = 'unit-test-application-secret';

    public function testEveryPurposeGetsItsOwnKey(): void
    {
        $keys = [
            PurposeKey::derive(self::SECRET, PurposeKey::ACCESS_TOKEN),
            PurposeKey::derive(self::SECRET, PurposeKey::OAUTH_STATE),
            PurposeKey::derive(self::SECRET, PurposeKey::NATIVE_AUTH_HANDOFF),
        ];

        self::assertCount(3, array_unique($keys));
        foreach ($keys as $key) {
            self::assertSame(32, strlen($key));
            self::assertNotSame(hash('sha256', self::SECRET, true), $key);
        }
    }

    public function testTheSameSecretAndPurposeGiveTheSameKeyOnEveryNode(): void
    {
        self::assertSame(
            PurposeKey::derive(self::SECRET, PurposeKey::ACCESS_TOKEN),
            PurposeKey::derive(self::SECRET, PurposeKey::ACCESS_TOKEN),
        );
        self::assertNotSame(
            PurposeKey::derive(self::SECRET, PurposeKey::ACCESS_TOKEN),
            PurposeKey::derive(self::SECRET.'x', PurposeKey::ACCESS_TOKEN),
        );
    }

    public function testAnEmptySecretNeverSignsAnything(): void
    {
        $this->expectException(\LogicException::class);

        PurposeKey::derive('', PurposeKey::ACCESS_TOKEN);
    }

    public function testAnOAuthStateIsNotAHandoffToken(): void
    {
        $state = new OAuthStateService(new NullLogger(), self::SECRET);
        $handoff = new NativeAuthHandoffService(new NullLogger(), new ArrayAdapter(), self::SECRET);

        [$payload, $signature] = explode('.', $state->generateState('google'));

        self::assertNull($handoff->validate(rtrim(strtr($payload, '+/', '-_'), '=').'.'.$signature));
    }

    public function testASignatureMadeWithTheBareSecretIsRefused(): void
    {
        $state = new OAuthStateService(new NullLogger(), self::SECRET);
        $json = json_encode(['provider' => 'google', 'nonce' => 'n', 'created_at' => time()], \JSON_THROW_ON_ERROR);

        self::assertNull($state->validateState(base64_encode($json).'.'.hash_hmac('sha256', $json, self::SECRET), 'google'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Repository\ConfigRepository;
use App\Service\Federation\FederationSigner;
use App\Service\PlatformLink\NotifyKeyStore;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotifyKeyStoreTest extends TestCase
{
    public function testANotificationVerifiesWithThePublishedKey(): void
    {
        $store = $this->store();
        $signer = new FederationSigner();
        $signing = $store->signingKey();
        $body = $signer->signBody([
            'type' => 'delivery',
            'id' => '9',
            'instance_id' => 'pi_abc',
            'ts' => 100,
            'kid' => $signer->fingerprint($signing['publicKey']),
        ], $signing['secretKey']);

        $signer->verifyBody($body, $store->publicKeys()[0]['publicKey']);
        self::assertSame($store->publicKeys()[0]['kid'], $body['kid']);
    }

    public function testRotationKeepsSigningWithThePreviousKeyUntilTheNextHour(): void
    {
        $store = $this->store();
        $before = $store->signingKey()['publicKey'];
        $store->rotate();

        self::assertSame($before, $store->signingKey()['publicKey']);
        self::assertCount(2, $store->publicKeys());
    }

    private function store(): NotifyKeyStore
    {
        $saved = null;
        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('getValue')->willReturnCallback(static function () use (&$saved): ?string {
            return $saved;
        });
        $configs->method('setValue')->willReturnCallback(static function (int $owner, string $group, string $setting, string $value) use (&$saved): \App\Entity\Config {
            $saved = $value;

            return new \App\Entity\Config();
        });
        $encryption = new EncryptionService('test-secret', new NullLogger());

        return new NotifyKeyStore($configs, $encryption, new FederationSigner());
    }
}

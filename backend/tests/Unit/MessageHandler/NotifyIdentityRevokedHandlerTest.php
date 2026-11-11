<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Message\NotifyIdentityRevoked;
use App\MessageHandler\NotifyIdentityRevokedHandler;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\PlatformLink\SignedNotificationClient;
use PHPUnit\Framework\TestCase;

final class NotifyIdentityRevokedHandlerTest extends TestCase
{
    public function testAReactivatedIdentityIsNotRevokedAgain(): void
    {
        $identity = new ExternalIdentity();
        $identity->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $client = $this->createMock(SignedNotificationClient::class);
        $client->expects(self::never())->method('post');

        $this->handler($identities, $client)(new NotifyIdentityRevoked('pi_abc', '4', 10));
    }

    public function testAStaleRevocationIsDropped(): void
    {
        $identity = new ExternalIdentity();
        $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
        $identity->setRevokedAt(20);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $client = $this->createMock(SignedNotificationClient::class);
        $client->expects(self::never())->method('post');

        $this->handler($identities, $client)(new NotifyIdentityRevoked('pi_abc', '4', 10));
    }

    public function testEachRevocationOfTheSameLinkHasItsOwnId(): void
    {
        $identity = new ExternalIdentity();
        $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
        $identity->setExternalId('ada');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $instances = $this->createStub(PlatformInstanceRepository::class);
        $instances->method('findByInstanceId')->willReturn(new PlatformInstance('nextcloud', 'pi_abc', 'cloud.example.org'));
        $sent = [];
        $client = $this->createStub(SignedNotificationClient::class);
        $client->method('post')->willReturnCallback(static function (PlatformInstance $instance, string $type, string $id) use (&$sent): array {
            $sent[] = $id;

            return [];
        });
        $handler = new NotifyIdentityRevokedHandler($instances, $identities, $client);

        $identity->setRevokedAt(10);
        $handler(new NotifyIdentityRevoked('pi_abc', '4', 10));
        $identity->setRevokedAt(20);
        $handler(new NotifyIdentityRevoked('pi_abc', '4', 20));

        self::assertSame(['4.10', '4.20'], $sent, 'a second revocation after reconnecting is not a replay');
    }

    private function handler(ExternalIdentityRepository $identities, SignedNotificationClient $client): NotifyIdentityRevokedHandler
    {
        return new NotifyIdentityRevokedHandler(
            $this->createStub(PlatformInstanceRepository::class),
            $identities,
            $client,
        );
    }
}

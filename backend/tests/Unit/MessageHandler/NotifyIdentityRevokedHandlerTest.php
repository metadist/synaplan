<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\ExternalIdentity;
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

    private function handler(ExternalIdentityRepository $identities, SignedNotificationClient $client): NotifyIdentityRevokedHandler
    {
        return new NotifyIdentityRevokedHandler(
            $this->createStub(PlatformInstanceRepository::class),
            $identities,
            $client,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\LinkCodeService;
use App\Service\PlatformLink\PlatformInstanceService;
use App\Service\PlatformLink\PlatformLinkExchangeService;
use PHPUnit\Framework\TestCase;

final class PlatformLinkExchangeServiceTest extends TestCase
{
    public function testExchangeNeverTouchesUsers(): void
    {
        $user = new User();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setValue($user, 7);
        $user->setMail('linked@example.com');
        $user->setUserDetails(['display_name' => 'Linked']);

        $instance = new PlatformInstance(
            PlatformInstance::CLIENT_NEXTCLOUD,
            'pi_aabbccddeeff',
            'files.example.org',
            ['https://files.example.org/cb'],
            PlatformInstance::STATUS_ACTIVE,
            0,
        );

        $instanceService = $this->createMock(PlatformInstanceService::class);
        $instanceService->method('requireInstance')->willReturn($instance);
        $instanceService->expects(self::once())->method('assertSecret');

        $codes = $this->createMock(LinkCodeService::class);
        $codes->method('consume')->willReturn([
            'userId' => 7,
            'instanceId' => 'pi_aabbccddeeff',
            'externalId' => 'jdoe',
            'redirectUri' => 'https://files.example.org/cb',
            'withMemories' => false,
            'expiresAt' => time() + 60,
        ]);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);
        $users->expects(self::exactly(2))->method('count')->willReturn(12);
        $users->expects(self::never())->method('save');

        $keys = $this->createMock(ApiKeyRepository::class);
        $keys->expects(self::once())->method('save')->willReturnCallback(
            static function (ApiKey $key): void {
                $ref = new \ReflectionProperty(ApiKey::class, 'id');
                $ref->setValue($key, 99);
            }
        );

        $identity = new ExternalIdentity();
        $identityRef = new \ReflectionProperty(ExternalIdentity::class, 'id');
        $identityRef->setValue($identity, 5);

        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->method('upsert')->willReturn($identity);

        $service = new PlatformLinkExchangeService(
            $instanceService,
            $codes,
            $users,
            $keys,
            $identities,
            $this->createStub(PlatformInstanceRepository::class),
            $this->createStub(AuditLogWriter::class),
        );

        $result = $service->exchange('pi_aabbccddeeff', 'secret', str_repeat('ab', 16), '127.0.0.1');

        self::assertArrayHasKey('success', $result);
        self::assertSame(7, $result['user']['id']);
        self::assertSame('linked@example.com', $result['user']['email']);
        self::assertSame('linked@example.com', $user->getMail());
        self::assertSame(['display_name' => 'Linked'], $user->getUserDetails());
    }
}

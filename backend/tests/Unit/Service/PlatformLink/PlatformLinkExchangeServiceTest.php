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
use App\Service\PlatformLink\DeliveryNotifier;
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
            $this->createStub(DeliveryNotifier::class),
        );

        $result = $service->exchange('pi_aabbccddeeff', 'secret', str_repeat('ab', 16), '127.0.0.1');

        self::assertArrayHasKey('success', $result);
        self::assertSame(7, $result['user']['id']);
        self::assertSame('linked@example.com', $result['user']['email']);
        self::assertSame('linked@example.com', $user->getMail());
        self::assertSame(['display_name' => 'Linked'], $user->getUserDetails());
    }

    public function testRevokingAKeyEndsTheLinkThatHoldsIt(): void
    {
        $identity = $this->identity(5, 7, 99);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->expects(self::once())->method('findOneByApiKeyId')->with(99)->willReturn($identity);
        $identities->expects(self::once())->method('remove')->with($identity);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')
            ->with(7, 'platform_link.disconnected', 'platform_link', '5', null, '127.0.0.1');

        $this->service(identities: $identities, audit: $audit)->endLinkOfKey(7, 99, '127.0.0.1');
    }

    public function testAKeyOfNoLinkOrOfAnotherPersonEndsNothing(): void
    {
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findOneByApiKeyId')->willReturnCallback(
            fn (int $apiKeyId): ?ExternalIdentity => 99 === $apiKeyId ? $this->identity(5, 8, 99) : null,
        );
        $identities->expects(self::never())->method('remove');
        $service = $this->service(identities: $identities);

        $service->endLinkOfKey(7, 98, '127.0.0.1');
        $service->endLinkOfKey(7, 99, '127.0.0.1');
    }

    public function testALinkWhoseKeyIsGoneIsNotListed(): void
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findByUserId')->willReturn([$this->identity(5, 7, 99), $this->identity(6, 7, 100)]);
        $instances = $this->createStub(PlatformInstanceRepository::class);
        $instances->method('findByInstanceId')->willReturn(new PlatformInstance(
            PlatformInstance::CLIENT_NEXTCLOUD,
            'pi_aabbccddeeff',
            'files.example.org',
            ['https://files.example.org/cb'],
            PlatformInstance::STATUS_ACTIVE,
            0,
        ));
        $key = new ApiKey();
        $key->setName('Nextcloud files.example.org');
        $keys = $this->createStub(ApiKeyRepository::class);
        $keys->method('find')->willReturnCallback(static fn (mixed $id): ?ApiKey => 99 === $id ? $key : null);

        $links = $this->service(keys: $keys, identities: $identities, instances: $instances)->listForUser(7);

        self::assertSame([5], array_column($links, 'id'));
    }

    private function identity(int $id, int $userId, ?int $apiKeyId): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        (new \ReflectionProperty(ExternalIdentity::class, 'id'))->setValue($identity, $id);
        $identity->setUserId($userId);
        $identity->setInstanceId('pi_aabbccddeeff');
        $identity->setExternalId('ada');
        $identity->setApiKeyId($apiKeyId);

        return $identity;
    }

    private function service(
        ?ApiKeyRepository $keys = null,
        ?ExternalIdentityRepository $identities = null,
        ?PlatformInstanceRepository $instances = null,
        ?AuditLogWriter $audit = null,
    ): PlatformLinkExchangeService {
        return new PlatformLinkExchangeService(
            $this->createStub(PlatformInstanceService::class),
            $this->createStub(LinkCodeService::class),
            $this->createStub(UserRepository::class),
            $keys ?? $this->createStub(ApiKeyRepository::class),
            $identities ?? $this->createStub(ExternalIdentityRepository::class),
            $instances ?? $this->createStub(PlatformInstanceRepository::class),
            $audit ?? $this->createStub(AuditLogWriter::class),
            $this->createStub(DeliveryNotifier::class),
        );
    }
}

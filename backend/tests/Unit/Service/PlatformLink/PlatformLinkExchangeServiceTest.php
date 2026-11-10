<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\DeliveryNotifier;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\PlatformLinkExchangeService;
use PHPUnit\Framework\TestCase;

final class PlatformLinkExchangeServiceTest extends TestCase
{
    public function testRevokingAKeyEndsTheLinkThatHoldsIt(): void
    {
        $identity = $this->identity(5, 7, 99);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->expects(self::once())->method('findOneByApiKeyId')->with(99)->willReturn($identity);
        $identities->expects(self::once())->method('save')->with($identity);
        $identities->expects(self::never())->method('remove');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')
            ->with(7, 'platform_link.disconnected', 'platform_link', '5', null, '127.0.0.1');

        $this->service(identities: $identities, audit: $audit)->endLinkOfKey(7, 99, '127.0.0.1');
        self::assertSame('revoked', $identity->getStatus());
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
        self::assertFalse($links[0]['can_copy']);
        self::assertFalse($links[0]['can_read']);
        self::assertSame(['mode' => 'watched', 'folders' => []], $links[0]['read_scope']);
    }

    public function testACloudThatAcceptsCopiesIsOffered(): void
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findByUserId')->willReturn([$this->identity(5, 7, 99)]);
        $instance = new PlatformInstance(
            PlatformInstance::CLIENT_NEXTCLOUD,
            'pi_aabbccddeeff',
            'files.example.org',
            null,
            PlatformInstance::STATUS_ACTIVE,
        );
        $instance->setDeliveryUrl('https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound');
        $instance->setAppCapabilities(['deliveries', 'unknown']);
        $instances = $this->createStub(PlatformInstanceRepository::class);
        $instances->method('findByInstanceId')->willReturn($instance);
        $key = new ApiKey();
        $key->setName('Nextcloud');
        $keys = $this->createStub(ApiKeyRepository::class);
        $keys->method('find')->willReturn($key);

        $links = $this->service(keys: $keys, identities: $identities, instances: $instances)->listForUser(7);

        self::assertTrue($links[0]['can_copy']);
    }

    public function testACloudThatHandsOutFilesSaysWhatSynaplanMayRead(): void
    {
        $identity = $this->identity(5, 7, 99);
        $identity->setReadScope(['mode' => 'allowed', 'folders' => [
            ['id' => '11', 'path' => 'Invoices/2026'],
            ['id' => '12', 'path' => ''],
        ]]);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findByUserId')->willReturn([$identity]);
        $instance = new PlatformInstance(PlatformInstance::CLIENT_NEXTCLOUD, 'pi_aabbccddeeff', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
        $instance->setDeliveryUrl('https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound');
        $instance->setAppCapabilities(['pickups', 'read_scope']);
        $instances = $this->createStub(PlatformInstanceRepository::class);
        $instances->method('findByInstanceId')->willReturn($instance);
        $key = new ApiKey();
        $key->setName('Nextcloud');
        $keys = $this->createStub(ApiKeyRepository::class);
        $keys->method('find')->willReturn($key);

        $links = $this->service(keys: $keys, identities: $identities, instances: $instances)->listForUser(7);

        self::assertFalse($links[0]['can_copy']);
        self::assertTrue($links[0]['can_read']);
        self::assertSame(['mode' => 'allowed', 'folders' => ['Invoices/2026']], $links[0]['read_scope']);
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
            $keys ?? $this->createStub(ApiKeyRepository::class),
            $identities ?? $this->createStub(ExternalIdentityRepository::class),
            $instances ?? $this->createStub(PlatformInstanceRepository::class),
            $audit ?? $this->createStub(AuditLogWriter::class),
            $this->createStub(DeliveryNotifier::class),
            $this->createStub(LifecycleEffect::class),
            $this->createStub(OAuthTokenRepository::class),
        );
    }
}

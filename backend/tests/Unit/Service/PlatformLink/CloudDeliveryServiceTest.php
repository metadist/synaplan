<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\CloudDelivery;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Repository\CloudDeliveryRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\FileRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Destination\ShareableFile;
use App\Service\File\FileStorageService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\CloudDeliveryService;
use App\Service\PlatformLink\DeliveryNotifier;
use App\Service\PlatformLink\PlatformLinksConfig;
use PHPUnit\Framework\TestCase;

final class CloudDeliveryServiceTest extends TestCase
{
    public function testACopyWithoutALinkIsRefused(): void
    {
        $service = $this->service(identities: $this->createStub(ExternalIdentityRepository::class));

        $result = $service->copy(new ShareableFile(1, 7, '/tmp/a', 'a.txt', 3), ['identity_id' => 4]);

        self::assertFalse($result->ok);
        self::assertFalse($result->isPending());
    }

    public function testACopyIsQueuedAndWaitsWhenTheCloudDoesNotAnswer(): void
    {
        $identity = $this->identity(4, 7, 'pi_abc');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::once())->method('save')->willReturnCallback(static function (CloudDelivery $delivery): void {
            $ref = new \ReflectionProperty(CloudDelivery::class, 'id');
            $ref->setValue($delivery, 9);
        });
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('set')->with('cloud_delivery:ticket:9', 'pi_abc', 300);
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $platforms->method('findByInstanceId')->willReturn(new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE));

        $result = $this->service($deliveries, $identities, $platforms, redis: $redis)->copy(
            new ShareableFile(3, 7, '/tmp/a', 'notes.txt', 12),
            ['identity_id' => 4],
        );

        self::assertTrue($result->isPending());
        self::assertSame('9', $result->context['delivery_id']);
    }

    public function testAClaimHandsOutOneDownloadAndASecondClaimIsRefused(): void
    {
        $delivery = $this->delivery(9, 4);
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));
        $file = $this->createStub(File::class);
        $file->method('getDisplayName')->willReturn('notes.txt');
        $file->method('getFileMime')->willReturn('text/plain');
        $file->method('getFileSize')->willReturn(12);
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturn('0');
        $redis->method('getAndDelete')->willReturnOnConsecutiveCalls('pi_abc', null);

        $service = $this->service($deliveries, $identities, files: $files, redis: $redis);
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
        $claim = $service->claim($instance, 9);

        self::assertSame('notes.txt', $claim['name']);
        self::assertStringStartsWith('/api/v1/platform-links/deliveries/9/file?ticket=', $claim['download_path']);

        $this->expectException(CloudDeliveryException::class);
        $this->expectExceptionMessage('already_claimed');
        $service->claim($instance, 9);
    }

    public function testAnotherCloudCannotClaimTheCopy(): void
    {
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($this->delivery(9, 4));
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('increment');

        $this->expectException(CloudDeliveryException::class);
        $this->expectExceptionMessage('wrong_instance');
        $this->service($deliveries, $identities, redis: $redis)->claim(
            new PlatformInstance('nextcloud', 'pi_other', 'other.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
        );
    }

    public function testAnOldUnconfirmedCopyIsFailedAndALateResultCanStillDeliverIt(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markFailed('unconfirmed');
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->expects(self::once())->method('save');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));

        $state = $this->service($deliveries, $identities)->result(
            new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt', 'open_url' => 'https://files.example.org/f/1'],
        );

        self::assertSame('delivered', $state['state']);
        self::assertSame('Synaplan/Documents/notes.txt', $delivery->getRemotePath());
    }

    public function testTheSweepFailsOnlyRowsThatAreStillQueued(): void
    {
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::once())->method('failQueuedOlderThan')->willReturn(2);

        self::assertSame(2, $this->service($deliveries)->sweep());
    }

    public function testAResultWithoutAStatusLeavesTheCopyQueued(): void
    {
        $delivery = $this->delivery(9, 4);
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->expects(self::never())->method('save');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));

        $state = $this->service($deliveries, $identities)->result(
            new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
            [],
        );

        self::assertSame('queued', $state['state']);
    }

    public function testACopyIsRefusedWhenLinkingIsOff(): void
    {
        $links = $this->createStub(PlatformLinksConfig::class);
        $links->method('isEnabled')->willReturn(false);
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::never())->method('save');

        $result = $this->service($deliveries, links: $links)->copy(
            new ShareableFile(3, 7, '/tmp/a', 'notes.txt', 12),
            ['identity_id' => 4],
        );

        self::assertFalse($result->ok);
    }

    public function testAnExpiredCopyCannotBeClaimed(): void
    {
        $delivery = $this->delivery(9, 4);
        (new \ReflectionProperty(CloudDelivery::class, 'created'))->setValue($delivery, time() - 301);
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));

        $this->expectException(CloudDeliveryException::class);
        $this->expectExceptionMessage('expired');
        $this->service($deliveries, $identities)->claim(
            new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
        );
    }

    public function testACloudThatKeepsGuessingIsToldToSlowDown(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturn('120');
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::never())->method('find');

        $this->expectException(CloudDeliveryException::class);
        $this->expectExceptionMessage('rate_limited');
        $this->service($deliveries, redis: $redis)->claim(
            new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
        );
    }

    public function testTheSameResultTwiceDoesNotChangeADeliveredCopy(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markDelivered('Synaplan/Documents/notes.txt', '1', 'https://files.example.org/f/1');
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->expects(self::never())->method('save');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));

        $state = $this->service($deliveries, $identities)->result(
            new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org', null, PlatformInstance::STATUS_ACTIVE),
            9,
            ['status' => 'failed', 'error_code' => 'conflict'],
        );

        self::assertSame('delivered', $state['state']);
    }

    private function delivery(int $id, int $identityId): CloudDelivery
    {
        $delivery = new CloudDelivery(7, 3, $identityId, CloudDelivery::TARGET_KIND_FOLDER, null, CloudDelivery::CONFLICT_RENAME);
        $ref = new \ReflectionProperty(CloudDelivery::class, 'id');
        $ref->setValue($delivery, $id);

        return $delivery;
    }

    private function identity(int $id, int $userId, string $instanceId): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        (new \ReflectionProperty(ExternalIdentity::class, 'id'))->setValue($identity, $id);
        $identity->setUserId($userId);
        $identity->setInstanceId($instanceId);
        $identity->setExternalId('ada');

        return $identity;
    }

    private function enabledLinks(): PlatformLinksConfig
    {
        $links = $this->createStub(PlatformLinksConfig::class);
        $links->method('isEnabled')->willReturn(true);

        return $links;
    }

    private function service(
        ?CloudDeliveryRepository $deliveries = null,
        ?ExternalIdentityRepository $identities = null,
        ?PlatformInstanceRepository $platforms = null,
        ?FileRepository $files = null,
        ?RedisService $redis = null,
        ?PlatformLinksConfig $links = null,
    ): CloudDeliveryService {
        return new CloudDeliveryService(
            $deliveries ?? $this->createStub(CloudDeliveryRepository::class),
            $identities ?? $this->createStub(ExternalIdentityRepository::class),
            $platforms ?? $this->createStub(PlatformInstanceRepository::class),
            $files ?? $this->createStub(FileRepository::class),
            $this->createStub(FileStorageService::class),
            $redis ?? $this->createStub(RedisService::class),
            $this->createStub(DeliveryNotifier::class),
            $this->createStub(AuditLogWriter::class),
            $links ?? $this->enabledLinks(),
        );
    }
}

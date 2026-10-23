<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\CloudPickup;
use App\Entity\CloudPickupFile;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Repository\CloudPickupFileRepository;
use App\Repository\CloudPickupRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\File\FileStorageService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\DeliveryNotifier;
use App\Service\PlatformLink\PickupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PickupServiceTest extends TestCase
{
    public function testAFolderOutsideTheReadScopeNeverOpensATicket(): void
    {
        $identity = $this->identity();
        $identity->setReadScope(['mode' => 'watched', 'folders' => []]);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->expects(self::never())->method('save');
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::never())->method('set');

        $result = $this->service($pickups, $identities, redis: $redis)->open(7, 4, ['folder_path' => 'Invoices/2026'], 'fetch');

        self::assertFalse($result['ok']);
        self::assertSame('outside_scope', $result['error']);
        self::assertStringContainsString('Nothing was read.', $result['sentence']);
        self::assertStringContainsString('Invoices/2026', $result['sentence']);
    }

    public function testTheHourlyLimitRefusesBeforeATicket(): void
    {
        $identity = $this->identity();
        $identity->setReadScope(['mode' => 'all', 'folders' => []]);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->method('countSince')->willReturn(20);
        $pickups->expects(self::never())->method('save');

        $result = $this->service($pickups, $identities)->open(7, 4, ['folder_path' => 'Docs'], 'fetch');

        self::assertSame('rate_limited', $result['error']);
    }

    public function testClaimHandsTheSelectorOnceAndAReplayIsRefused(): void
    {
        $pickup = $this->pickup(9);
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity());
        $redis = $this->createStub(RedisService::class);
        $redis->method('getAndDelete')->willReturnOnConsecutiveCalls('pi_abc', null);

        $service = $this->service($pickups, $identities, redis: $redis);
        $claim = $service->claim($this->instance(), 9);

        self::assertSame('ada', $claim['external_id']);
        self::assertSame('fetch', $claim['mode']);
        self::assertSame(50, $claim['limits']['max_files']);
        try {
            $service->claim($this->instance(), 9);
            self::fail('A second claim must be refused.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('already_claimed', $e->errorCode);
        }
    }

    public function testAClaimFromAnotherInstanceIsRefused(): void
    {
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($this->pickup(9));

        try {
            $this->service($pickups)->claim($this->instance('pi_other'), 9);
            self::fail('Another instance must not claim this pickup.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testAnExpiredTicketIsRefused(): void
    {
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($this->pickup(9, created: time() - 700));

        try {
            $this->service($pickups)->claim($this->instance(), 9);
            self::fail('An expired pickup must be refused.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(410, $e->status);
        }
    }

    public function testReuseIsLimitedToTheSameInstance(): void
    {
        $heldRow = new CloudPickupFile(7, 'pi_abc', 'remote-1', 'v1', 99);
        $held = $this->createStub(CloudPickupFileRepository::class);
        $held->method('findHeld')->willReturnCallback(
            static fn (int $owner, string $instance, string $remote, string $version): ?CloudPickupFile => 'pi_abc' === $instance && 'remote-1' === $remote && 'v1' === $version ? $heldRow : null,
        );
        $samePickup = $this->pickup(3);
        $samePickup->markClaimed();
        $same = $this->createStub(CloudPickupRepository::class);
        $same->method('find')->willReturn($samePickup);
        $otherPickup = $this->pickup(4, 'pi_other');
        $otherPickup->markClaimed();
        $other = $this->createStub(CloudPickupRepository::class);
        $other->method('find')->willReturn($otherPickup);
        $redis = $this->createStub(RedisService::class);

        $reused = $this->service(pickups: $same, held: $held, redis: $redis)->listing($this->instance(), 3, [
            ['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10],
        ]);
        $uploaded = $this->service(pickups: $other, held: $held, redis: $redis)->listing($this->instance('pi_other'), 4, [
            ['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10],
        ]);

        self::assertSame([['id' => 'remote-1', 'file_id' => 99]], $reused['reuse']);
        self::assertSame([], $reused['upload']);
        self::assertSame(['remote-1'], $uploaded['upload']);
        self::assertSame([], $uploaded['reuse']);
    }

    public function testAFileOver100MegabytesIsSkipped(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);

        $answer = $this->service($pickups)->listing($this->instance(), 3, [
            ['id' => 'big', 'version' => 'v1', 'name' => 'big.pdf', 'size' => PickupService::MAX_FILE_BYTES + 1],
        ]);

        self::assertSame([], $answer['upload']);
        self::assertSame([['id' => 'big', 'reason' => 'too_large']], $answer['skip']);
    }

    public function testResultIsIdempotent(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $service = $this->service($pickups);

        $first = $service->result($this->instance(), 3, ['status' => 'done', 'listed' => 1, 'sent' => 1, 'skipped' => []]);
        $second = $service->result($this->instance(), 3, ['status' => 'failed', 'error_code' => 'unreachable']);

        self::assertSame('done', $first['state']);
        self::assertSame('done', $second['state']);
    }

    public function testAnUploadThatWasNotRequestedIsRefused(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturn('[]');

        try {
            $this->service($pickups, redis: $redis)->storeFile($this->instance(), 3, 'remote-9', 'v1', 'hi', 'a.txt', 'text/plain');
            self::fail('An id outside the upload list must be refused.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testARequestedUploadIsStoredAsAHiddenWorkingCopy(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturn('["remote-1"]');
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '07/file.txt',
            'size' => 2,
            'mime' => 'text/plain',
            'error' => null,
        ]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof File) {
                (new \ReflectionProperty(File::class, 'id'))->setValue($entity, 42);
                self::assertFalse($entity->isIncoming());
                self::assertTrue($entity->isEphemeral());
                self::assertSame('nextcloud', $entity->getSource());
            }
        });
        $held = $this->createMock(CloudPickupFileRepository::class);
        $held->expects(self::once())->method('save');

        $fileId = $this->service($pickups, held: $held, storage: $storage, em: $em, redis: $redis)
            ->storeFile($this->instance(), 3, 'remote-1', 'v1', 'hi', 'a.txt', 'text/plain');

        self::assertSame(42, $fileId);
        self::assertSame([42], $pickup->fileIds());
    }

    private function service(
        ?CloudPickupRepository $pickups = null,
        ?ExternalIdentityRepository $identities = null,
        ?PlatformInstanceRepository $platforms = null,
        ?CloudPickupFileRepository $held = null,
        ?FileStorageService $storage = null,
        ?EntityManagerInterface $em = null,
        ?RedisService $redis = null,
    ): PickupService {
        return new PickupService(
            $pickups ?? $this->createStub(CloudPickupRepository::class),
            $held ?? $this->createStub(CloudPickupFileRepository::class),
            $identities ?? $this->createStub(ExternalIdentityRepository::class),
            $platforms ?? $this->platforms(),
            $storage ?? $this->createStub(FileStorageService::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $redis ?? $this->createStub(RedisService::class),
            $this->createStub(DeliveryNotifier::class),
            $this->createStub(AuditLogWriter::class),
        );
    }

    private function platforms(): PlatformInstanceRepository
    {
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $platforms->method('findByInstanceId')->willReturn($this->instance());

        return $platforms;
    }

    private function identity(): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        (new \ReflectionProperty(ExternalIdentity::class, 'id'))->setValue($identity, 4);
        $identity->setUserId(7);
        $identity->setInstanceId('pi_abc');
        $identity->setExternalId('ada');

        return $identity;
    }

    private function instance(string $instanceId = 'pi_abc'): PlatformInstance
    {
        $instance = new PlatformInstance('nextcloud', $instanceId, 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
        $instance->setDeliveryUrl('https://files.example.org/apps/synaplan/inbound');
        $instance->setAppCapabilities(['pickups']);

        return $instance;
    }

    private function pickup(int $id, string $instanceId = 'pi_abc', int $created = 0): CloudPickup
    {
        $pickup = new CloudPickup(7, 4, $instanceId, CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        (new \ReflectionProperty(CloudPickup::class, 'id'))->setValue($pickup, $id);
        if ($created > 0) {
            (new \ReflectionProperty(CloudPickup::class, 'created'))->setValue($pickup, $created);
        }

        return $pickup;
    }
}

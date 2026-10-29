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
use App\Service\PlatformLink\PlatformLinksConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PickupServiceTest extends TestCase
{
    public function testAPausedIdentityNeverOpensATicket(): void
    {
        $identity = $this->identity();
        $identity->setStatus(ExternalIdentity::STATUS_PAUSED);
        $identity->setReadScope(['mode' => 'all', 'folders' => []]);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->expects(self::never())->method('save');

        $result = $this->service($pickups, $identities)->open(7, 4, ['folder_path' => 'Docs'], 'fetch');

        self::assertFalse($result['ok']);
        self::assertSame('unauthorized', $result['error']);
    }

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
        $file = (new File())->setFilePath('07/a.pdf');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($file);
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('fileExists')->willReturn(true);
        $same = $this->createStub(CloudPickupRepository::class);
        $same->method('find')->willReturn($samePickup);
        $otherPickup = $this->pickup(4, 'pi_other');
        $otherPickup->markClaimed();
        $other = $this->createStub(CloudPickupRepository::class);
        $other->method('find')->willReturn($otherPickup);
        $redis = $this->createStub(RedisService::class);

        $reused = $this->service(pickups: $same, held: $held, storage: $storage, em: $em, redis: $redis)->listing($this->instance(), 3, [
            ['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10],
        ]);
        $otherIdentity = $this->identity();
        $otherIdentity->setInstanceId('pi_other');
        $otherIdentities = $this->createStub(ExternalIdentityRepository::class);
        $otherIdentities->method('find')->willReturn($otherIdentity);
        $uploaded = $this->service(pickups: $other, identities: $otherIdentities, held: $held, storage: $storage, em: $em, redis: $redis)->listing($this->instance('pi_other'), 4, [
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
        self::assertSame([['id' => 'big', 'name' => 'big.pdf', 'reason' => 'too_large']], $pickup->skipped());
    }

    public function testTheResultKeepsTheNamesSynaplanRecorded(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $service = $this->service($this->ledger($pickup));
        $service->listing($this->instance(), 3, [
            ['id' => 'big', 'version' => 'v1', 'name' => 'big.pdf', 'size' => PickupService::MAX_FILE_BYTES + 1],
        ]);

        $service->result($this->instance(), 3, ['status' => 'partial', 'skipped' => [
            ['id' => 'big', 'reason' => 'too_large'],
            ['id' => 'remote-2', 'reason' => 'unreadable'],
        ]]);

        self::assertSame(CloudPickup::STATE_PARTIAL, $pickup->getState());
        self::assertSame([
            ['id' => 'big', 'name' => 'big.pdf', 'reason' => 'too_large'],
            ['id' => 'remote-2', 'reason' => 'unreadable'],
        ], $pickup->skipped());
    }

    public function testResultIsIdempotent(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $service = $this->service($this->ledger($pickup));

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
        $held->expects(self::once())->method('insertIgnore')->willReturn(true);

        $fileId = $this->service($pickups, held: $held, storage: $storage, em: $em, redis: $redis)
            ->storeFile($this->instance(), 3, 'remote-1', 'v1', 'hi', 'a.txt', 'text/plain');

        self::assertSame(42, $fileId);
        self::assertSame([42], $pickup->fileIds());
    }

    public function testLinkingTurnedOffNeverOpensATicket(): void
    {
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->expects(self::never())->method('save');

        $result = $this->service($pickups, links: $this->links(false))->open(7, 4, ['folder_path' => 'Docs'], 'fetch');

        self::assertFalse($result['ok']);
        self::assertSame('not_linked', $result['error']);
        self::assertStringContainsString('Nothing was read.', $result['sentence']);
    }

    public function testNumericRemoteIdsComeBackAsStrings(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);

        $answer = $this->service($pickups)->listing($this->instance(), 3, [
            ['id' => '1027191588', 'version' => 'v1', 'name' => 'inv-0101.txt', 'size' => 76],
        ]);

        self::assertSame(['1027191588'], $answer['upload']);
    }

    public function testTheFilesAPickupProducedCarryTheirIdsForLaterSteps(): void
    {
        $pickup = $this->pickup(3);
        $pickup->rememberFile(42);
        $file = (new File())->setFilePath('07/inv-0101.pdf');
        $file->setFileName('inv-0101.pdf');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($file);

        $files = $this->service(em: $em)->producedFiles($pickup);

        self::assertSame([[
            'path' => '/api/v1/files/uploads/07/inv-0101.pdf',
            'type' => 'document',
            'local_path' => '07/inv-0101.pdf',
            'name' => 'inv-0101.pdf',
            'file_id' => 42,
        ]], $files);
    }

    public function testARunThatStopsWaitingFailsThePickupAndDropsItsTicket(): void
    {
        $pickup = $this->pickup(9);
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('delete')->with('pickup:ticket:9');

        self::assertTrue($this->service($this->ledger($pickup), redis: $redis)->giveUp(9));

        self::assertSame(CloudPickup::STATE_FAILED, $pickup->getState());
        self::assertSame('unconfirmed', $pickup->getErrorCode());
    }

    public function testAResultThatLandedFirstIsNotGivenUp(): void
    {
        $pickup = $this->pickup(9);
        $pickup->finish(CloudPickup::STATE_DONE, 1, 1, [], null);
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::never())->method('delete');

        self::assertFalse($this->service($this->ledger($pickup), redis: $redis)->giveUp(9));

        self::assertSame(CloudPickup::STATE_DONE, $pickup->getState());
    }

    public function testAResultAfterTheRunGaveUpDoesNotReopenIt(): void
    {
        $pickup = $this->pickup(9);
        $pickup->markClaimed();
        $service = $this->service($this->ledger($pickup));

        $service->giveUp(9);
        $answer = $service->result($this->instance(), 9, ['status' => 'done', 'listed' => 1, 'sent' => 1]);

        self::assertSame(CloudPickup::STATE_FAILED, $answer['state']);
        self::assertSame('unconfirmed', $pickup->getErrorCode());
    }

    public function testTheSweepFailsPickupsNoRunWaitsFor(): void
    {
        $pickups = $this->createMock(CloudPickupRepository::class);
        $pickups->expects(self::once())->method('failOpenOlderThan')
            ->with(self::lessThanOrEqual(time() - 1200), 'unconfirmed')
            ->willReturn(2);

        self::assertSame(2, $this->service($pickups)->sweep());
    }

    public function testANamesOnlyPickupDoesNotAskForUploads(): void
    {
        $pickup = new CloudPickup(7, 4, 'pi_abc', CloudPickup::MODE_LIST, ['folder_path' => 'Docs']);
        (new \ReflectionProperty(CloudPickup::class, 'id'))->setValue($pickup, 3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);

        $store = [];
        $service = $this->service($pickups, redis: $this->memoryRedis($store));
        $items = [['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10]];

        $first = $service->listing($this->instance(), 3, $items);
        $second = $service->listing($this->instance(), 3, $items);

        self::assertSame($first, $second);
        self::assertSame([], $first['upload']);
        self::assertSame(['a.pdf'], $pickup->names());
    }

    public function testALaterListingKeepsTheEarlierCount(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickup->addAccepted(PickupService::MAX_FILES, 0);
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);

        $answer = $this->service($pickups)->listing($this->instance(), 3, [
            ['id' => 'remote-9', 'version' => 'v1', 'name' => 'b.pdf', 'size' => 10],
        ]);

        self::assertSame([['id' => 'remote-9', 'reason' => 'over_limit']], $answer['skip']);
    }

    public function testARepeatedListingDoesNotSpendTheLimitTwice(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $store = [];
        $redis = $this->memoryRedis($store);
        $service = $this->service($pickups, redis: $redis);
        $two = [
            ['id' => '1027191588', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10],
            ['id' => 'remote-2', 'version' => 'v1', 'name' => 'b.pdf', 'size' => 20],
            ['id' => 'big', 'version' => 'v1', 'name' => 'big.pdf', 'size' => PickupService::MAX_FILE_BYTES + 1],
        ];

        $first = $service->listing($this->instance(), 3, $two);
        $second = $service->listing($this->instance(), 3, $two);

        self::assertSame(['1027191588', 'remote-2'], $first['upload']);
        self::assertSame([['id' => 'big', 'reason' => 'too_large']], $first['skip']);
        self::assertSame($first, $second);
        self::assertSame(2, $pickup->acceptCount());
        self::assertSame(30, $pickup->acceptBytes());
        self::assertSame([['id' => 'big', 'name' => 'big.pdf', 'reason' => 'too_large']], $pickup->skipped());
        self::assertSame(['a.pdf', 'b.pdf'], $pickup->names());
        $reserved = json_decode($store['pickup:upload:3'] ?? '', true);
        self::assertSame(10, $reserved['1027191588'] ?? null);
        self::assertSame(20, $reserved['remote-2'] ?? null);

        $third = $service->listing($this->instance(), 3, [
            ...$two,
            ['id' => 'remote-3', 'version' => 'v1', 'name' => 'c.pdf', 'size' => 5],
        ]);

        self::assertSame(['1027191588', 'remote-2', 'remote-3'], $third['upload']);
        self::assertSame([['id' => 'big', 'reason' => 'too_large']], $third['skip']);
        self::assertSame(3, $pickup->acceptCount());
        self::assertSame(35, $pickup->acceptBytes());
        self::assertSame(['a.pdf', 'b.pdf', 'c.pdf'], $pickup->names());
        self::assertCount(1, $pickup->skipped());

        $duplicate = $service->listing($this->instance(), 3, [
            ['id' => 'remote-3', 'version' => 'v1', 'name' => 'c.pdf', 'size' => 5],
            ['id' => 'remote-3', 'version' => 'v1', 'name' => 'c.pdf', 'size' => 5],
        ]);

        self::assertSame(['remote-3'], $duplicate['upload']);
        self::assertSame(3, $pickup->acceptCount());
        self::assertSame(35, $pickup->acceptBytes());
    }

    public function testARepeatedReuseReturnsTheSameFileWithoutSpendingAgain(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $heldRow = new CloudPickupFile(7, 'pi_abc', 'remote-1', 'v1', 99);
        $held = $this->createStub(CloudPickupFileRepository::class);
        $held->method('findHeld')->willReturn($heldRow);
        $file = (new File())->setFilePath('07/a.pdf');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($file);
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('fileExists')->willReturn(true);
        $store = [];
        $service = $this->service($pickups, held: $held, storage: $storage, em: $em, redis: $this->memoryRedis($store));
        $items = [['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10]];

        $first = $service->listing($this->instance(), 3, $items);
        $second = $service->listing($this->instance(), 3, $items);

        self::assertSame([['id' => 'remote-1', 'file_id' => 99]], $first['reuse']);
        self::assertSame($first, $second);
        self::assertSame([], $first['upload']);
        self::assertSame(1, $pickup->acceptCount());
        self::assertSame(10, $pickup->acceptBytes());
        self::assertSame([99], $pickup->fileIds());
        self::assertSame(['a.pdf'], $pickup->names());
    }

    public function testAMissingWorkingCopyIsUploadedAgain(): void
    {
        $held = $this->createStub(CloudPickupFileRepository::class);
        $held->method('findHeld')->willReturn(new CloudPickupFile(7, 'pi_abc', 'remote-1', 'v1', 99));
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $answer = $this->service(pickups: $pickups, held: $held, em: $em)->listing($this->instance(), 3, [
            ['id' => 'remote-1', 'version' => 'v1', 'name' => 'a.pdf', 'size' => 10],
        ]);

        self::assertSame(['remote-1'], $answer['upload']);
        self::assertSame([], $answer['reuse']);
    }

    public function testFindRereadsAPickupAnotherRequestUpdated(): void
    {
        $pickup = $this->pickup(9);
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->expects(self::once())->method('refresh')->willReturnCallback(static function () use ($pickup): void {
            (new \ReflectionProperty(CloudPickup::class, 'state'))->setValue($pickup, CloudPickup::STATE_DONE);
        });

        $found = $this->service($pickups, em: $em)->find(9);

        self::assertInstanceOf(CloudPickup::class, $found);
        self::assertSame(CloudPickup::STATE_DONE, $found->getState());
    }

    public function testALostUploadRaceReturnsTheCopyThatWon(): void
    {
        $pickup = $this->pickup(3);
        $pickup->markClaimed();
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $winner = new CloudPickupFile(7, 'pi_abc', 'remote-1', 'v1', 77);
        $held = $this->createStub(CloudPickupFileRepository::class);
        $held->method('insertIgnore')->willReturn(false);
        $held->method('findHeld')->willReturn($winner);
        $file = (new File())->setFilePath('07/a.txt');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($file);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof File) {
                (new \ReflectionProperty(File::class, 'id'))->setValue($entity, 42);
            }
        });
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true, 'path' => '07/a.txt', 'size' => 2, 'mime' => 'text/plain', 'error' => null,
        ]);
        $storage->method('fileExists')->willReturn(true);
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturn('["remote-1"]');

        $fileId = $this->service($pickups, held: $held, storage: $storage, em: $em, redis: $redis)
            ->storeFile($this->instance(), 3, 'remote-1', 'v1', 'hi', 'a.txt', 'text/plain');

        self::assertSame(77, $fileId);
    }

    private function service(
        ?CloudPickupRepository $pickups = null,
        ?ExternalIdentityRepository $identities = null,
        ?PlatformInstanceRepository $platforms = null,
        ?CloudPickupFileRepository $held = null,
        ?FileStorageService $storage = null,
        ?EntityManagerInterface $em = null,
        ?RedisService $redis = null,
        ?PlatformLinksConfig $links = null,
    ): PickupService {
        return new PickupService(
            $pickups ?? $this->createStub(CloudPickupRepository::class),
            $held ?? $this->createStub(CloudPickupFileRepository::class),
            $identities ?? $this->identities(),
            $platforms ?? $this->platforms(),
            $storage ?? $this->createStub(FileStorageService::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $redis ?? $this->createStub(RedisService::class),
            $this->createStub(DeliveryNotifier::class),
            $this->createStub(AuditLogWriter::class),
            $links ?? $this->links(),
        );
    }

    /**
     * A repository that ends a pickup only while it is open, as the database
     * update does.
     */
    private function ledger(CloudPickup $pickup): CloudPickupRepository
    {
        $pickups = $this->createStub(CloudPickupRepository::class);
        $pickups->method('find')->willReturn($pickup);
        $pickups->method('finishIfOpen')->willReturnCallback(
            /** @param list<array<string, mixed>>|null $skipped */
            static function (int $id, string $state, ?string $errorCode, ?int $listed = null, ?int $sent = null, ?array $skipped = null) use ($pickup): bool {
                if (!in_array($pickup->getState(), [CloudPickup::STATE_QUEUED, CloudPickup::STATE_CLAIMED], true)) {
                    return false;
                }
                $pickup->finish($state, $listed ?? $pickup->getListed(), $sent ?? $pickup->getSent(), $skipped ?? $pickup->skipped(), $errorCode);

                return true;
            },
        );

        return $pickups;
    }

    /**
     * @param array<string, string> $store
     */
    private function memoryRedis(array &$store): RedisService
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store): ?string {
            return $store[$key] ?? null;
        });
        $redis->method('set')->willReturnCallback(static function (string $key, string $value, ?int $ttl = null) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

        return $redis;
    }

    private function links(bool $enabled = true): PlatformLinksConfig
    {
        $links = $this->createStub(PlatformLinksConfig::class);
        $links->method('isEnabled')->willReturn($enabled);

        return $links;
    }

    private function identities(): ExternalIdentityRepository
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity());

        return $identities;
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

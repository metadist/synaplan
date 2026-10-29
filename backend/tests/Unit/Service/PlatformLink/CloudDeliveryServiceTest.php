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
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DestinationResult;
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
    public function testAPausedIdentityIsNotACopyDestination(): void
    {
        $identity = $this->identity(4, 7, 'pi_abc');
        $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($identity);
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::never())->method('save');

        $result = $this->service($deliveries, $identities)->copy(new ShareableFile(1, 7, '/tmp/a', 'a.txt', 3), ['identity_id' => 4]);

        self::assertFalse($result->ok);
    }

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
        $deliveries->method('claimIfQueued')->willReturn(true);
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
        self::assertSame(CloudDelivery::STATE_CLAIMED, $delivery->getState());
        self::assertFalse($delivery->wasDownloaded());

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

    public function testAnOldUnconfirmedCopyStaysFailedUntilTheFileWasDownloaded(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markFailed('unconfirmed');
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->method('recordDelivered')->willReturn(false);
        $deliveries->expects(self::never())->method('save');

        $state = $this->service($deliveries, $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt', 'open_url' => 'https://files.example.org/f/1'],
        );

        self::assertSame('failed', $state['state']);
        self::assertNull($delivery->getRemotePath());
    }

    public function testALateResultAfterTheDownloadStillSendsTheCopy(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markFailed('unconfirmed');
        $delivery->markDownloaded();
        $deliveries = $this->ledger($delivery);

        $state = $this->service($deliveries, $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt', 'open_url' => 'https://files.example.org/f/1'],
        );

        self::assertSame('delivered', $state['state']);
        self::assertSame('Synaplan/Documents/notes.txt', $delivery->getRemotePath());
    }

    public function testTheSweepFailsRowsThatAreStillQueuedOrClaimed(): void
    {
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::once())->method('failOpenOlderThan')->willReturn(2);

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
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->expects(self::never())->method('claimIfQueued');

        try {
            $this->service($deliveries, $this->identities())->claim($this->instance(), 9);
            self::fail('An expired copy was claimed.');
        } catch (CloudDeliveryException $e) {
            self::assertSame('expired', $e->errorCode);
            self::assertSame(CloudDelivery::STATE_QUEUED, $delivery->getState());
        }
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

    public function testAResultOnAQueuedCopyDoesNotSendIt(): void
    {
        $delivery = $this->delivery(9, 4);
        $state = $this->service($this->ledger($delivery), $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt'],
        );

        self::assertSame(CloudDelivery::STATE_QUEUED, $state['state']);
        self::assertSame(CloudDelivery::STATE_QUEUED, $delivery->getState());
    }

    public function testAClaimWithoutADownloadDoesNotCountAsSent(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $state = $this->service($this->ledger($delivery), $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt'],
        );

        self::assertSame(CloudDelivery::STATE_CLAIMED, $state['state']);
        self::assertNull($delivery->getRemotePath());
    }

    public function testAClaimedCopyCanFailBeforeTheDownload(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $state = $this->service($this->ledger($delivery), $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'failed', 'error_code' => 'not_found'],
        );

        self::assertSame(CloudDelivery::STATE_FAILED, $state['state']);
        self::assertSame('not_found', $delivery->getErrorCode());
    }

    public function testAFailedResultOnAQueuedCopyStaysQueued(): void
    {
        $delivery = $this->delivery(9, 4);
        $state = $this->service($this->ledger($delivery), $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'failed', 'error_code' => 'quota'],
        );

        self::assertSame(CloudDelivery::STATE_QUEUED, $state['state']);
    }

    public function testADownloadThenAResultCountsAsSent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rv6');
        self::assertIsString($path);
        file_put_contents($path, 'hi');
        try {
            $delivery = $this->delivery(9, 4);
            $delivery->markClaimed();
            $file = $this->createStub(File::class);
            $file->method('getDisplayName')->willReturn('/Shared/Invoices/notes.txt');
            $file->method('getFileMime')->willReturn('text/plain');
            $file->method('getFilePath')->willReturn('notes.txt');
            $files = $this->createStub(FileRepository::class);
            $files->method('find')->willReturn($file);
            $redis = $this->createStub(RedisService::class);
            $redis->method('get')->willReturn('ticket');
            $redis->method('getAndDelete')->willReturn('ticket');
            $storage = $this->createStub(FileStorageService::class);
            $storage->method('getAbsolutePath')->willReturn($path);

            $service = $this->service($this->ledger($delivery), $this->identities(), files: $files, redis: $redis, storage: $storage);
            $downloaded = $service->download($this->instance(), 9, 'ticket');
            $state = $service->result($this->instance(), 9, [
                'status' => 'delivered',
                'remote_path' => 'Synaplan/Documents/notes.txt',
            ]);

            self::assertSame($path, $downloaded['path']);
            // The copy in the cloud is a plain file name, never the source path.
            self::assertSame('notes.txt', $downloaded['name']);
            self::assertTrue($delivery->wasDownloaded());
            self::assertSame(CloudDelivery::STATE_DELIVERED, $state['state']);
        } finally {
            unlink($path);
        }
    }

    public function testAHeaderOnlyDownloadDoesNotTakeTheFile(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->expects(self::never())->method('markDownloaded');
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::never())->method('getAndDelete');

        try {
            $this->service($deliveries, redis: $redis)->download($this->instance(), 9, 'ticket', false);
            self::fail('A header-only request took the download.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('method_not_allowed', $e->errorCode);
            self::assertFalse($delivery->wasDownloaded());
        }
    }

    public function testAMissingFileDoesNotBurnTheDownloadTicket(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $deliveries = $this->createMock(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->expects(self::never())->method('markDownloaded');
        $file = $this->createStub(File::class);
        $file->method('getFilePath')->willReturn('missing.txt');
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
        $redis = $this->createMock(RedisService::class);
        $redis->method('get')->willReturn('ticket');
        $redis->expects(self::never())->method('getAndDelete');
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('getAbsolutePath')->willReturn('/tmp/synaplan-rv6-missing');

        try {
            $this->service($deliveries, $this->identities(), files: $files, redis: $redis, storage: $storage)
                ->download($this->instance(), 9, 'ticket');
            self::fail('A missing file was downloaded.');
        } catch (CloudDeliveryException $e) {
            self::assertSame('unknown_delivery', $e->errorCode);
            self::assertFalse($delivery->wasDownloaded());
        }
    }

    public function testADeliveredNotifyWithoutADownloadStaysPending(): void
    {
        [$delivery, $result] = $this->notifiedCopy(['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt']);

        self::assertTrue($result->isPending());
        self::assertSame(CloudDelivery::STATE_QUEUED, $delivery->getState());
    }

    public function testADeliveredNotifyAfterTheDownloadCountsAsSent(): void
    {
        [$delivery, $result] = $this->notifiedCopy(
            ['status' => 'delivered', 'remote_path' => 'Synaplan/Documents/notes.txt', 'open_url' => 'https://files.example.org/f/1'],
            static function (CloudDelivery $delivery): void {
                $delivery->markClaimed();
                $delivery->markDownloaded();
            },
        );

        self::assertTrue($result->ok);
        self::assertFalse($result->isPending());
        self::assertSame(CloudDelivery::STATE_DELIVERED, $delivery->getState());
        self::assertSame('Synaplan/Documents/notes.txt', $result->reference);
    }

    public function testADeliveredNotifyCountsWhenTheResultAlreadyLanded(): void
    {
        $saved = null;
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('save')->willReturnCallback(static function (CloudDelivery $delivery) use (&$saved): void {
            (new \ReflectionProperty(CloudDelivery::class, 'id'))->setValue($delivery, 9);
            $saved = $delivery;
        });
        $deliveries->method('recordDelivered')->willReturn(false);
        $deliveries->method('refreshDelivery')->willReturnCallback(static function () use (&$saved): void {
            if ($saved instanceof CloudDelivery) {
                $saved->markDelivered('Synaplan/Documents/notes.txt', '1', 'https://files.example.org/f/1');
            }
        });
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $platforms->method('findByInstanceId')->willReturn($this->instance());
        $notifier = $this->createStub(DeliveryNotifier::class);
        $notifier->method('delivery')->willReturn([
            'status' => 'delivered',
            'remote_path' => 'the-late-body.txt',
        ]);

        $result = $this->service($deliveries, $this->identities(), $platforms, notifier: $notifier)->copy(
            new ShareableFile(3, 7, '/tmp/a', 'notes.txt', 12),
            ['identity_id' => 4],
        );

        self::assertTrue($result->ok);
        self::assertFalse($result->isPending());
        self::assertSame('Synaplan/Documents/notes.txt', $result->reference);
        self::assertInstanceOf(CloudDelivery::class, $saved);
        self::assertSame(CloudDelivery::STATE_DELIVERED, $saved->getState());
    }

    public function testADeliveredNotifyReportsAFailureThatAlreadyWon(): void
    {
        $result = $this->notifyAgainstStoredFailure(['status' => 'delivered', 'remote_path' => 'late.txt']);

        self::assertFalse($result->ok);
        self::assertFalse($result->isPending());
        self::assertSame(DestinationFailureCode::QuotaExceeded, $result->code);
    }

    public function testAFailedNotifyReportsTheFailureThatWon(): void
    {
        $result = $this->notifyAgainstStoredFailure(['status' => 'failed', 'error_code' => 'conflict']);

        self::assertFalse($result->ok);
        self::assertFalse($result->isPending());
        self::assertSame(DestinationFailureCode::QuotaExceeded, $result->code);
    }

    public function testAFailedNotifyRecordsTheCloudError(): void
    {
        [$delivery, $result] = $this->notifiedCopy(['status' => 'failed', 'error_code' => 'quota']);

        self::assertFalse($result->ok);
        self::assertSame(CloudDelivery::STATE_FAILED, $delivery->getState());
        self::assertSame('quota', $delivery->getErrorCode());
    }

    public function testALostResultSeesTheStateThatWon(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $delivery->markDownloaded();
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->method('recordDelivered')->willReturn(false);
        $deliveries->method('refreshDelivery')->willReturnCallback(static function () use ($delivery): void {
            $delivery->markDelivered('Synaplan/Documents/notes.txt', '9', null);
        });

        $state = $this->service($deliveries, $this->identities())->result(
            $this->instance(),
            9,
            ['status' => 'delivered', 'remote_path' => 'the-loser.txt'],
        );

        self::assertSame(CloudDelivery::STATE_DELIVERED, $state['state']);
        self::assertSame('Synaplan/Documents/notes.txt', $delivery->getRemotePath());
    }

    public function testTwoResultsOneWins(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $delivery->markDownloaded();
        $service = $this->service($this->ledger($delivery), $this->identities());

        $first = $service->result($this->instance(), 9, [
            'status' => 'delivered',
            'remote_path' => 'Synaplan/Documents/notes.txt',
        ]);
        $second = $service->result($this->instance(), 9, [
            'status' => 'failed',
            'error_code' => 'conflict',
        ]);

        self::assertSame(CloudDelivery::STATE_DELIVERED, $first['state']);
        self::assertSame(CloudDelivery::STATE_DELIVERED, $second['state']);
        self::assertSame('Synaplan/Documents/notes.txt', $delivery->getRemotePath());
    }

    public function testAClaimedCopyStillReadsAsCopying(): void
    {
        $delivery = $this->delivery(9, 4);
        $delivery->markClaimed();
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('findForOwnerFiles')->willReturn([$delivery]);

        $rows = $this->service($deliveries)->listForOwner(7, [3]);

        self::assertSame(CloudDelivery::STATE_QUEUED, $rows[0]['state']);
        self::assertSame('', $rows[0]['error_code']);
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

    private function instance(string $instanceId = 'pi_abc'): PlatformInstance
    {
        return new PlatformInstance('nextcloud', $instanceId, 'files.example.org', null, PlatformInstance::STATUS_ACTIVE);
    }

    private function identities(): ExternalIdentityRepository
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('find')->willReturn($this->identity(4, 7, 'pi_abc'));

        return $identities;
    }

    /**
     * A repository that applies a delivery write only while the row still
     * matches, as the conditional update does.
     */
    private function ledger(CloudDelivery $delivery): CloudDeliveryRepository
    {
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('find')->willReturn($delivery);
        $deliveries->method('claimIfQueued')->willReturnCallback(static function () use ($delivery): bool {
            if (CloudDelivery::STATE_QUEUED !== $delivery->getState()) {
                return false;
            }
            $delivery->markClaimed();

            return true;
        });
        $deliveries->method('markDownloaded')->willReturnCallback(static function () use ($delivery): bool {
            if (CloudDelivery::STATE_CLAIMED !== $delivery->getState() || $delivery->wasDownloaded()) {
                return false;
            }
            $delivery->markDownloaded();

            return true;
        });
        $deliveries->method('recordDelivered')->willReturnCallback(
            static function (int $id, ?string $path, ?string $remoteId, ?string $url, string $unconfirmedCode) use ($delivery): bool {
                $late = CloudDelivery::STATE_FAILED === $delivery->getState()
                    && $unconfirmedCode === $delivery->getErrorCode();
                if (!$delivery->wasDownloaded() || (CloudDelivery::STATE_CLAIMED !== $delivery->getState() && !$late)) {
                    return false;
                }
                $delivery->markDelivered($path, $remoteId, $url);

                return true;
            },
        );
        $deliveries->method('recordFailed')->willReturnCallback(
            static function (int $id, string $errorCode, bool $includeQueued) use ($delivery): bool {
                $open = CloudDelivery::STATE_CLAIMED === $delivery->getState()
                    || ($includeQueued && CloudDelivery::STATE_QUEUED === $delivery->getState());
                if (!$open) {
                    return false;
                }
                $delivery->markFailed($errorCode);

                return true;
            },
        );

        return $deliveries;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function notifyAgainstStoredFailure(array $answer): DestinationResult
    {
        $slot = new class {
            public ?CloudDelivery $delivery = null;
        };
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('save')->willReturnCallback(static function (CloudDelivery $delivery) use ($slot): void {
            (new \ReflectionProperty(CloudDelivery::class, 'id'))->setValue($delivery, 9);
            $slot->delivery = $delivery;
        });
        $deliveries->method('recordDelivered')->willReturn(false);
        $deliveries->method('recordFailed')->willReturn(false);
        $deliveries->method('refreshDelivery')->willReturnCallback(static function () use ($slot): void {
            $slot->delivery?->markFailed(DestinationFailureCode::QuotaExceeded->value);
        });
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $platforms->method('findByInstanceId')->willReturn($this->instance());
        $notifier = $this->createStub(DeliveryNotifier::class);
        $notifier->method('delivery')->willReturn($answer);

        return $this->service($deliveries, $this->identities(), $platforms, notifier: $notifier)->copy(
            new ShareableFile(3, 7, '/tmp/a', 'notes.txt', 12),
            ['identity_id' => 4],
        );
    }

    /**
     * Runs one copy whose cloud answers inside the request.
     *
     * @param array<string, mixed>                 $answer
     * @param (callable(CloudDelivery): void)|null $duringNotify
     *
     * @return array{0: CloudDelivery, 1: DestinationResult}
     */
    private function notifiedCopy(array $answer, ?callable $duringNotify = null): array
    {
        $slot = new class {
            public ?CloudDelivery $delivery = null;
        };
        $deliveries = $this->createStub(CloudDeliveryRepository::class);
        $deliveries->method('save')->willReturnCallback(static function (CloudDelivery $delivery) use ($slot): void {
            (new \ReflectionProperty(CloudDelivery::class, 'id'))->setValue($delivery, 9);
            $slot->delivery = $delivery;
        });
        $deliveries->method('recordDelivered')->willReturnCallback(
            static function (int $id, ?string $path, ?string $remoteId, ?string $url, string $unconfirmedCode) use ($slot): bool {
                $delivery = $slot->delivery;
                if (!$delivery instanceof CloudDelivery || !$delivery->wasDownloaded()) {
                    return false;
                }
                $late = CloudDelivery::STATE_FAILED === $delivery->getState()
                    && $unconfirmedCode === $delivery->getErrorCode();
                if (CloudDelivery::STATE_CLAIMED !== $delivery->getState() && !$late) {
                    return false;
                }
                $delivery->markDelivered($path, $remoteId, $url);

                return true;
            },
        );
        $deliveries->method('recordFailed')->willReturnCallback(
            static function (int $id, string $errorCode, bool $includeQueued) use ($slot): bool {
                $delivery = $slot->delivery;
                if (!$delivery instanceof CloudDelivery) {
                    return false;
                }
                $open = CloudDelivery::STATE_CLAIMED === $delivery->getState()
                    || ($includeQueued && CloudDelivery::STATE_QUEUED === $delivery->getState());
                if (!$open) {
                    return false;
                }
                $delivery->markFailed($errorCode);

                return true;
            },
        );
        $platforms = $this->createStub(PlatformInstanceRepository::class);
        $platforms->method('findByInstanceId')->willReturn($this->instance());
        $notifier = $this->createStub(DeliveryNotifier::class);
        $notifier->method('delivery')->willReturnCallback(static function () use ($slot, $answer, $duringNotify): array {
            if (null !== $duringNotify && $slot->delivery instanceof CloudDelivery) {
                $duringNotify($slot->delivery);
            }

            return $answer;
        });
        $result = $this->service($deliveries, $this->identities(), $platforms, notifier: $notifier)->copy(
            new ShareableFile(3, 7, '/tmp/a', 'notes.txt', 12),
            ['identity_id' => 4],
        );
        self::assertInstanceOf(CloudDelivery::class, $slot->delivery);

        return [$slot->delivery, $result];
    }

    private function service(
        ?CloudDeliveryRepository $deliveries = null,
        ?ExternalIdentityRepository $identities = null,
        ?PlatformInstanceRepository $platforms = null,
        ?FileRepository $files = null,
        ?RedisService $redis = null,
        ?PlatformLinksConfig $links = null,
        ?FileStorageService $storage = null,
        ?DeliveryNotifier $notifier = null,
    ): CloudDeliveryService {
        return new CloudDeliveryService(
            $deliveries ?? $this->createStub(CloudDeliveryRepository::class),
            $identities ?? $this->createStub(ExternalIdentityRepository::class),
            $platforms ?? $this->createStub(PlatformInstanceRepository::class),
            $files ?? $this->createStub(FileRepository::class),
            $storage ?? $this->createStub(FileStorageService::class),
            $redis ?? $this->createStub(RedisService::class),
            $notifier ?? $this->createStub(DeliveryNotifier::class),
            $this->createStub(AuditLogWriter::class),
            $links ?? $this->enabledLinks(),
        );
    }
}

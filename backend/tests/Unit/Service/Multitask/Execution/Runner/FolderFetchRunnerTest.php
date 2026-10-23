<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\CloudPickup;
use App\Entity\Connection;
use App\Entity\Message;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\DavException;
use App\Service\Dav\WebDavClient;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\FolderFetchRunner;
use App\Service\Multitask\Execution\Runner\FolderRead;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\PlatformLink\CloudFolderLink;
use App\Service\PlatformLink\PickupService;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Security\SsrfGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FolderFetchRunnerTest extends TestCase
{
    public function testListNamesTheFilesAndDoesNotDownloadThem(): void
    {
        $webDav = $this->createMock(WebDavClient::class);
        $webDav->expects(self::once())->method('list')->willReturn([
            ['name' => 'notes.txt', 'path' => 'Docs/notes.txt', 'size' => 12],
        ]);
        $webDav->expects(self::never())->method('get');

        $result = $this->webDavRunner($webDav)->run($this->node(['mode' => 'list', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertSame('notes.txt', $result->text);
        self::assertSame([], $result->files);
        self::assertSame(FolderRead::LISTED, $result->metadata['folder_read']['outcome']);
        self::assertSame(['notes.txt'], $result->metadata['folder_read']['files']);
    }

    public function testFetchAttachesTheFiles(): void
    {
        $webDav = $this->createStub(WebDavClient::class);
        $webDav->method('list')->willReturn([
            ['name' => 'notes.txt', 'path' => 'Docs/notes.txt', 'size' => 5],
        ]);
        $webDav->method('get')->willReturn('hello');

        $result = $this->webDavRunner($webDav)->run($this->node(['mode' => 'fetch', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertSame('07/notes.txt', $result->files[0]['local_path']);
        self::assertStringContainsString('Read 1 files from Nextcloud › Docs: notes.txt', (string) $result->text);
        $read = $result->metadata['folder_read'];
        self::assertSame(FolderRead::READ, $read['outcome']);
        self::assertSame(['webdav', 'Nextcloud', 'Docs', 1, 1], [$read['app'], $read['label'], $read['folder'], $read['read'], $read['total']]);
        self::assertSame(['notes.txt'], $read['files']);
    }

    public function testFetchFailsClosedWhenNothingCouldBeRead(): void
    {
        $webDav = $this->createMock(WebDavClient::class);
        $webDav->method('list')->willReturn([]);
        $webDav->expects(self::never())->method('get');

        $result = $this->webDavRunner($webDav)->run($this->node(['mode' => 'fetch', 'folder' => 'Docs']), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Nothing was read.', (string) $result->error);
        self::assertSame([], $result->files);
        self::assertSame(FolderRead::NOTHING, $result->metadata['folder_read']['outcome']);
    }

    public function testAPartialFetchSaysWhatWasLeftOut(): void
    {
        $webDav = $this->createStub(WebDavClient::class);
        $webDav->method('list')->willReturn([
            ['name' => 'big.pdf', 'path' => 'Docs/big.pdf', 'size' => PickupService::MAX_FILE_BYTES + 1],
            ['name' => 'notes.txt', 'path' => 'Docs/notes.txt', 'size' => 5],
        ]);
        $webDav->method('get')->willReturn('hello');

        $result = $this->webDavRunner($webDav)->run($this->node(['mode' => 'fetch', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->metadata['partial']);
        self::assertStringContainsString('larger than 100 MB', (string) $result->text);
        self::assertCount(1, $result->files);
        $read = $result->metadata['folder_read'];
        self::assertSame(FolderRead::PARTIAL, $read['outcome']);
        self::assertSame([1, 2], [$read['read'], $read['total']]);
        self::assertSame([['name' => 'big.pdf', 'reason' => 'too_large']], $read['skipped']);
    }

    public function testALaterReadFailureKeepsTheFilesAlreadyFetched(): void
    {
        $webDav = $this->createStub(WebDavClient::class);
        $webDav->method('list')->willReturn([
            ['name' => 'notes.txt', 'path' => 'Docs/notes.txt', 'size' => 5],
            ['name' => 'later.txt', 'path' => 'Docs/later.txt', 'size' => 5],
            ['name' => 'last.txt', 'path' => 'Docs/last.txt', 'size' => 5],
        ]);
        $webDav->method('get')->willReturnCallback(static function (): string {
            static $calls = 0;
            ++$calls;
            if ($calls > 1) {
                throw new DavException(0, 'Could not reach files.example.org');
            }

            return 'hello';
        });

        $result = $this->webDavRunner($webDav)->run($this->node(['mode' => 'fetch', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->metadata['partial']);
        self::assertCount(1, $result->files);
        self::assertSame('Read 1 of 3 files from Nextcloud › Docs: notes.txt; 2 could not be read.', $result->text);
        $read = $result->metadata['folder_read'];
        self::assertSame([1, 3], [$read['read'], $read['total']]);
        self::assertSame([
            ['name' => 'later.txt', 'reason' => 'unreadable'],
            ['name' => 'last.txt', 'reason' => 'unreadable'],
        ], $read['skipped']);
        self::assertSame(['too_large' => 0, 'over_limit' => 0, 'unreadable' => 2], $read['left_out']);
    }

    public function testARefusedScopeReturnsTheSentenceAndDoesNotWait(): void
    {
        $pickups = $this->createMock(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn([
            'ok' => false,
            'error' => 'outside_scope',
            'pickup_id' => 0,
            'sentence' => 'Synaplan may not read Invoices/2026 in your Nextcloud. Nothing was read.',
        ]);
        $pickups->expects(self::never())->method('find');

        $result = $this->cloudRunner($pickups)->run(
            $this->node(['channel' => 'nextcloud', 'folder' => 'Invoices/2026']),
            $this->context(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('Nothing was read.', (string) $result->error);
        $read = $result->metadata['folder_read'];
        self::assertSame([FolderRead::REFUSED, 'Nextcloud', 'Invoices/2026'], [$read['outcome'], $read['label'], $read['folder']]);
        self::assertSame('https://files.example.org/index.php/apps/files/?dir=%2FInvoices%2F2026', $read['open_url']);
    }

    public function testACloudThatNeverAnswersFailsThePickupAndSaysSo(): void
    {
        $pickup = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $pickups = $this->createMock(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn(['ok' => true, 'error' => '', 'pickup_id' => 9, 'sentence' => '']);
        $pickups->method('find')->willReturn($pickup);
        $pickups->expects(self::once())->method('giveUp')->with(9)->willReturn(true);

        $result = $this->cloudRunner($pickups)->run($this->node(['channel' => 'nextcloud', 'folder' => 'Docs']), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertSame('Synaplan could not reach files.example.org in time. Nothing was read.', $result->error);
        self::assertSame(FolderRead::UNREACHABLE, $result->metadata['folder_read']['outcome']);
    }

    public function testAResultThatLandsWhileGivingUpIsUsed(): void
    {
        $open = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $done = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $done->finish(CloudPickup::STATE_DONE, 1, 1, [], null);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn(['ok' => true, 'error' => '', 'pickup_id' => 9, 'sentence' => '']);
        $pickups->method('find')->willReturnOnConsecutiveCalls($open, $done);
        $pickups->method('giveUp')->willReturn(false);
        $pickups->method('producedFiles')->willReturn([
            ['path' => '/api/v1/files/uploads/07/a.pdf', 'type' => 'document', 'local_path' => '07/a.pdf', 'name' => 'a.pdf'],
        ]);

        $result = $this->cloudRunner($pickups)->run($this->node(['channel' => 'nextcloud', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertSame(FolderRead::READ, $result->metadata['folder_read']['outcome']);
        self::assertCount(1, $result->files);
    }

    public function testWaitingForTheCloudReportsProgressOnTheCard(): void
    {
        $pickup = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $pickup->addAccepted(50, 0);
        $pickup->addSkipped([
            ['id' => 'r51', 'name' => 'scan-1.tiff', 'reason' => 'too_large'],
            ['id' => 'r52', 'name' => 'scan-2.tiff', 'reason' => 'over_limit'],
            ['id' => 'r53', 'name' => 'notes.md', 'reason' => 'type_not_read'],
        ]);
        $pickup->rememberFile(7);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn(['ok' => true, 'error' => '', 'pickup_id' => 9, 'sentence' => '']);
        $pickups->method('find')->willReturn($pickup);
        $context = $this->context();
        $events = [];
        $context->setProgressSink(static function (string $nodeId, array $progress) use (&$events): void {
            $events[] = $progress['folder_read'];
        });

        $this->cloudRunner($pickups)->run($this->node(['channel' => 'nextcloud', 'folder' => 'Docs']), $context);

        self::assertCount(1, $events);
        self::assertSame([FolderRead::READING, 1, 52, 'Nextcloud'], [$events[0]['outcome'], $events[0]['read'], $events[0]['total'], $events[0]['label']]);
    }

    public function testAPartialPickupNamesWhatWasLeftOut(): void
    {
        $pickup = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $pickup->finish(CloudPickup::STATE_PARTIAL, 3, 1, [
            ['id' => 'r2', 'name' => 'scan.tiff', 'reason' => 'too_large'],
            ['id' => 'r3', 'name' => 'notes.md', 'reason' => 'type_not_read'],
        ], null);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn(['ok' => true, 'error' => '', 'pickup_id' => 9, 'sentence' => '']);
        $pickups->method('find')->willReturn($pickup);
        $pickups->method('producedFiles')->willReturn([
            ['path' => '/api/v1/files/uploads/07/a.pdf', 'type' => 'document', 'local_path' => '07/a.pdf', 'name' => 'a.pdf'],
        ]);

        $result = $this->cloudRunner($pickups)->run($this->node(['channel' => 'nextcloud', 'folder' => 'Docs']), $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertSame('Read 1 of 2 files from Nextcloud › Docs: a.pdf; 1 was larger than 100 MB and left out.', $result->text);
        $read = $result->metadata['folder_read'];
        self::assertSame([FolderRead::PARTIAL, 1, 2], [$read['outcome'], $read['read'], $read['total']]);
        self::assertSame([['name' => 'scan.tiff', 'reason' => 'too_large']], $read['skipped']);
    }

    public function testASettledPickupAttachesItsFilesForTheNextStep(): void
    {
        $pickup = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $pickup->finish(CloudPickup::STATE_DONE, 1, 1, [], null);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('linkForChannel')->willReturn($this->link());
        $pickups->method('open')->willReturn(['ok' => true, 'error' => '', 'pickup_id' => 9, 'sentence' => '']);
        $pickups->method('find')->willReturn($pickup);
        $pickups->method('producedFiles')->willReturn([
            ['path' => '/api/v1/files/uploads/07/notes.txt', 'type' => 'document', 'local_path' => '07/notes.txt', 'name' => 'notes.txt'],
        ]);

        $runner = $this->cloudRunner($pickups);
        $context = $this->context();
        $result = $runner->run($this->node(['channel' => 'nextcloud', 'folder' => 'Docs']), $context);
        $context->setResult('n1', $result);

        $seen = (new StepInputResolver())->resolve(['from' => 'n1', 'field' => 'files'], $context);

        self::assertTrue($result->isSuccessful());
        self::assertSame($result->files, $seen);
        self::assertSame('07/notes.txt', $seen[0]['local_path']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function node(array $params): TaskNode
    {
        return new TaskNode('n1', Capability::FolderFetch, [], [], $params + ['connection_id' => 8]);
    }

    private function webDavRunner(WebDavClient $webDav): FolderFetchRunner
    {
        $connection = new Connection(1, 'webdav', 'Nextcloud');
        $connection->setCredentialId(5);
        $connection->setConfig([
            'base_url' => 'https://93.184.216.34/dav',
            'username' => 'ada',
            'channel' => 'nextcloud',
        ]);
        $connections = $this->createStub(ConnectionRepository::class);
        $connections->method('findByIdAndOwner')->willReturn($connection);
        $vault = $this->createStub(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn('secret');
        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '07/notes.txt',
            'size' => 5,
            'mime' => 'application/octet-stream',
            'error' => null,
        ]);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('linkForChannel')->willReturn(null);

        return new FolderFetchRunner(
            $connections,
            $vault,
            $webDav,
            $storage,
            $this->createStub(EntityManagerInterface::class),
            $pickups,
            $this->createStub(PlannerChannelCatalog::class),
            new CloudFolderLink(new RedirectUriPolicy(new SsrfGuard(), 'prod')),
            0,
        );
    }

    private function cloudRunner(PickupService $pickups): FolderFetchRunner
    {
        return new FolderFetchRunner(
            $this->createStub(ConnectionRepository::class),
            $this->createStub(CredentialVaultInterface::class),
            $this->createStub(WebDavClient::class),
            $this->createStub(FileStorageService::class),
            $this->createStub(EntityManagerInterface::class),
            $pickups,
            $this->createStub(PlannerChannelCatalog::class),
            new CloudFolderLink(new RedirectUriPolicy(new SsrfGuard(), 'prod')),
            0,
        );
    }

    /**
     * @return array{identity_id: int, channel: string, host: string, client: string, folders: list<string>}
     */
    private function link(): array
    {
        return ['identity_id' => 4, 'channel' => 'nextcloud', 'host' => 'files.example.org', 'client' => 'nextcloud', 'folders' => ['Docs']];
    }

    private function context(): NodeContext
    {
        return new NodeContext($this->message(), [], 1, ['language' => 'en']);
    }

    private function message(): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn('read the folder');
        $message->method('getUserId')->willReturn(1);
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return $message;
    }
}

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
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\PlatformLink\PickupService;
use App\Service\SavedTask\Graph\StepInputResolver;
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
        self::assertStringContainsString('Read 1 files', (string) $result->text);
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
    }

    public function testALaterReadFailureKeepsTheFilesAlreadyFetched(): void
    {
        $webDav = $this->createStub(WebDavClient::class);
        $webDav->method('list')->willReturn([
            ['name' => 'notes.txt', 'path' => 'Docs/notes.txt', 'size' => 5],
            ['name' => 'later.txt', 'path' => 'Docs/later.txt', 'size' => 5],
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
        self::assertStringContainsString('could not be read', (string) $result->text);
    }

    public function testARefusedScopeReturnsTheSentenceAndDoesNotWait(): void
    {
        $pickups = $this->createMock(PickupService::class);
        $pickups->method('identityForChannel')->willReturn(4);
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
    }

    public function testASettledPickupAttachesItsFilesForTheNextStep(): void
    {
        $pickup = new CloudPickup(1, 4, 'pi_abc', CloudPickup::MODE_FETCH, ['folder_path' => 'Docs']);
        $pickup->finish(CloudPickup::STATE_DONE, 1, 1, [], null);
        $pickups = $this->createStub(PickupService::class);
        $pickups->method('identityForChannel')->willReturn(4);
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
        $pickups->method('identityForChannel')->willReturn(null);

        return new FolderFetchRunner(
            $connections,
            $vault,
            $webDav,
            $storage,
            $this->createStub(EntityManagerInterface::class),
            $pickups,
            $this->createStub(PlannerChannelCatalog::class),
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
            0,
        );
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

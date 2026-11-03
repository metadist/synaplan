<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Office\Wopi;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Office\Wopi\OfficeEditorConfig;
use App\Service\Office\Wopi\WopiDiscovery;
use App\Service\Office\Wopi\WopiDiscoveryParser;
use App\Service\Office\Wopi\WopiDocumentService;
use App\Service\Office\Wopi\WopiFileUpdate;
use App\Service\Office\Wopi\WopiLockStore;
use App\Service\Office\Wopi\WopiTimestamp;
use App\Service\Office\Wopi\WopiTokenService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WopiHostTest extends TestCase
{
    private const SECRET = 'test-secret';

    private File $file;

    private WopiLockStore $locks;

    public function testTokenIsScopedToOneFileAndExpires(): void
    {
        $tokens = $this->tokens();
        $now = 1_700_000_000;
        $token = $tokens->issue(9, 4, $now);

        $access = $tokens->verify($token, $now + 10);
        $this->assertNotNull($access);
        $this->assertSame(9, $access->fileId);
        $this->assertSame(4, $access->userId);

        $this->assertNull($tokens->verify($token, $now + WopiTokenService::TTL_SECONDS));
        $this->assertNull($tokens->verify($token.'x', $now));
        $this->assertNull($this->tokens('other-secret')->verify($token, $now));
    }

    public function testDiscoveryParserKeepsThePathAndPrefersEdit(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/wopi/discovery.xml');
        $map = WopiDiscoveryParser::parse($xml);

        $this->assertSame('/browser/abc/cool.html?', $map['docx']['path']);
        $this->assertTrue($map['docx']['writable']);
        $this->assertSame('/browser/abc/cool.html?lang=en', $map['xlsx']['path']);
        $this->assertFalse($map['pdf']['writable']);
        $this->assertSame([], WopiDiscoveryParser::parse('<not-discovery/>'));
        $this->assertSame([], WopiDiscoveryParser::parse('not xml'));
    }

    public function testEditorUrlUsesThePublicHost(): void
    {
        $config = $this->config();
        $url = $config->editorUrl('/browser/abc/cool.html?', $config->wopiSrc(9));

        $this->assertStringStartsWith('http://localhost:9981/browser/abc/cool.html?WOPISrc=', $url);
        $this->assertStringContainsString(rawurlencode('http://backend:80/api/v1/wopi/files/9'), $url);
        $this->assertSame('http://localhost:5173', $config->postMessageOrigin());
        $this->assertSame('http://collabora:9980', $config->discoverySource());
    }

    public function testTimestampMatchesTheSecondCollaboraSendsBack(): void
    {
        $unix = 1_700_000_000;
        $this->assertTrue(WopiTimestamp::matches(WopiTimestamp::format($unix), $unix));
        $this->assertTrue(WopiTimestamp::matches('2023-11-14T22:13:20Z', $unix));
        $this->assertFalse(WopiTimestamp::matches(WopiTimestamp::format($unix + 5), $unix));
        $this->assertFalse(WopiTimestamp::matches('', $unix));
    }

    public function testLockConflictReturnsTheCurrentLock(): void
    {
        $locks = new WopiLockStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $this->assertTrue($locks->lock(3, 'alpha')['ok']);
        $conflict = $locks->lock(3, 'beta');
        $this->assertFalse($conflict['ok']);
        $this->assertSame('alpha', $conflict['current']);
        $this->assertTrue($locks->relock(3, 'alpha', 'beta')['ok']);
        $this->assertTrue($locks->refresh(3, 'beta')['ok']);
        $this->assertFalse($locks->unlock(3, 'alpha')['ok']);
        $this->assertTrue($locks->unlock(3, 'beta')['ok']);
        $this->assertSame('', $locks->current(3));
    }

    public function testCheckFileInfoShape(): void
    {
        $service = $this->service();
        $token = $this->tokens()->issue(9, 4);
        $access = $this->tokens()->verify($token);
        $this->assertNotNull($access);

        $result = $service->checkFileInfo($access);

        $this->assertSame(Response::HTTP_OK, $result->status);
        $this->assertSame('notes.docx', $result->json['BaseFileName']);
        $this->assertSame(12, $result->json['Size']);
        $this->assertSame('4', $result->json['OwnerId']);
        $this->assertSame('4', $result->json['UserId']);
        $this->assertSame('Ada', $result->json['UserFriendlyName']);
        $this->assertTrue($result->json['UserCanWrite']);
        $this->assertTrue($result->json['SupportsLocks']);
        $this->assertTrue($result->json['SupportsUpdate']);
        $this->assertTrue($result->json['UserCanNotWriteRelative']);
        $this->assertTrue($result->json['EnableInsertRemoteImage']);
        $this->assertSame('http://localhost:5173', $result->json['PostMessageOrigin']);
        $this->assertSame(WopiTimestamp::format(1_700_000_000), $result->json['LastModifiedTime']);
    }

    public function testPutFileRejectsAStaleTimestampWithoutWriting(): void
    {
        $dir = $this->tempDir();
        $path = $dir.'/notes.docx';
        file_put_contents($path, 'before');
        $service = $this->service($dir);
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);
        $this->assertTrue($this->locks->lock(9, 'held')['ok']);

        $result = $service->putFile($access, 'after', WopiTimestamp::format(1_700_000_050), 'held');

        $this->assertSame(Response::HTTP_CONFLICT, $result->status);
        $this->assertSame(WopiDocumentService::CONFLICT_CODE, $result->json['COOLStatusCode']);
        $this->assertSame('before', (string) file_get_contents($path));
    }

    public function testPutFileWritesAndBumpsTheTimestamp(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/notes.docx', 'before');
        $service = $this->service($dir, expectThumbnail: true);
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);
        $this->file->setFileText('stale text');
        $this->file->setVectorState(File::VECTOR_STATE_VECTORIZED);
        $this->file->setThumbPath('posters/notes.jpg');
        mkdir($dir.'/posters');
        file_put_contents($dir.'/posters/notes.jpg', 'poster');
        $this->assertTrue($this->locks->lock(9, 'held')['ok']);

        $result = $service->putFile($access, 'after', WopiTimestamp::format(1_700_000_000), 'held');

        $this->assertSame(Response::HTTP_OK, $result->status);
        $this->assertSame('after', (string) file_get_contents($dir.'/notes.docx'));
        $this->assertNotSame(WopiTimestamp::format(1_700_000_000), $result->json['LastModifiedTime']);
        $this->assertGreaterThan(1_700_000_000, $this->file->getUpdatedAt());
        $this->assertSame('', $this->file->getFileText());
        $this->assertSame('uploaded', $this->file->getStatus());
        $this->assertSame(File::VECTOR_STATE_PENDING, $this->file->getVectorState());
        $this->assertNull($this->file->getThumbPath());
        $this->assertFileDoesNotExist($dir.'/posters/notes.jpg');
    }

    public function testPutFileRejectsAnUnlockedFileThatAlreadyHasBytes(): void
    {
        $dir = $this->tempDir();
        $path = $dir.'/notes.docx';
        file_put_contents($path, 'before');
        $service = $this->service($dir);
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);

        $result = $service->putFile($access, 'after', '', '');

        $this->assertSame(Response::HTTP_CONFLICT, $result->status);
        $this->assertSame('', $result->headers['X-WOPI-Lock']);
        $this->assertSame('before', (string) file_get_contents($path));
    }

    public function testPutFileOnAnEmptyUnlockedFileIsAllowed(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/notes.docx', '');
        $service = $this->service($dir, fileSize: 0, expectThumbnail: true);
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);

        $result = $service->putFile($access, 'after', '', '');

        $this->assertSame(Response::HTTP_OK, $result->status);
        $this->assertSame('after', (string) file_get_contents($dir.'/notes.docx'));
    }

    public function testPutFileRejectsASavePastTheRemainingQuota(): void
    {
        $dir = $this->tempDir();
        $path = $dir.'/notes.docx';
        file_put_contents($path, 'before');
        $service = $this->service($dir, remainingBytes: 0);
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);
        $this->assertTrue($this->locks->lock(9, 'held')['ok']);

        $result = $service->putFile($access, str_repeat('x', 20), '', 'held');

        $this->assertSame(Response::HTTP_INSUFFICIENT_STORAGE, $result->status);
        $this->assertSame('before', (string) file_get_contents($path));
    }

    public function testLockWithAnOldLockRelocks(): void
    {
        $service = $this->service();
        $access = $this->tokens()->verify($this->tokens()->issue(9, 4));
        $this->assertNotNull($access);

        $this->assertSame(Response::HTTP_OK, $service->lock($access, 'LOCK', 'alpha', '')->status);
        $result = $service->lock($access, 'LOCK', 'beta', 'alpha');

        $this->assertSame(Response::HTTP_OK, $result->status);
        $this->assertSame('beta', $result->headers['X-WOPI-Lock']);
        $this->assertSame('beta', $this->locks->current(9));
    }

    public function testEditSessionIsOwnerOnlyAndHidesWhenUnconfigured(): void
    {
        $owner = $this->user(4);
        $other = $this->user(8);
        $dir = $this->tempDir();
        file_put_contents($dir.'/notes.docx', 'before');
        $service = $this->service($dir, discoveryXml: (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/wopi/discovery.xml'));

        $denied = $service->editSession($other, 9);
        $this->assertSame(Response::HTTP_FORBIDDEN, $denied->status);

        $session = $service->editSession($owner, 9);
        $this->assertSame(Response::HTTP_OK, $session->status);
        $this->assertStringContainsString('WOPISrc=', (string) $session->json['editorUrl']);
        $verified = $this->tokens()->verify((string) $session->json['accessToken']);
        $this->assertNotNull($verified);
        $this->assertSame(9, $verified->fileId);
        $this->assertSame(4, $verified->userId);

        $off = new OfficeEditorConfig('', '', '', 'http://localhost:5173');
        $hidden = $this->service($dir, $off)->editSession($owner, 9);
        $this->assertSame(Response::HTTP_NOT_FOUND, $hidden->status);
    }

    private function tokens(string $secret = self::SECRET): WopiTokenService
    {
        return new WopiTokenService(new NullLogger(), $secret);
    }

    private function config(): OfficeEditorConfig
    {
        return new OfficeEditorConfig(
            'http://localhost:9981',
            'http://backend:80',
            'http://collabora:9980',
            'http://localhost:5173',
        );
    }

    private function service(?string $dir = null, ?OfficeEditorConfig $config = null, bool $expectThumbnail = false, ?string $discoveryXml = null, int $fileSize = 12, int $remainingBytes = 1_073_741_824): WopiDocumentService
    {
        $dir ??= $this->tempDir();
        $file = new File();
        $id = new \ReflectionProperty(File::class, 'id');
        $id->setValue($file, 9);
        $file->setUserId(4);
        $file->setFileName('notes.docx');
        $file->setFilePath('notes.docx');
        $file->setFileSize($fileSize);
        $file->setFileType('docx');
        $file->setUpdatedAt(1_700_000_000);
        $file->setCreatedAt(1_700_000_000);
        $this->file = $file;

        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturnCallback(static function (int $id) use ($file): ?File {
            return 9 === $id ? $file : null;
        });
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(fn (int $id): ?User => 4 === $id ? $this->user(4) : null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($expectThumbnail ? $this->once() : $this->never())->method('flush');

        if ($expectThumbnail) {
            $bus = $this->createMock(MessageBusInterface::class);
            $bus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        } else {
            $bus = $this->createStub(MessageBusInterface::class);
        }
        $converter = new OfficeConverterClient(new MockHttpClient(), new NullLogger(), 'http://collabora:9980', 1000);
        $responses = [];
        if (null !== $discoveryXml) {
            $responses[] = new MockResponse($discoveryXml, ['http_code' => 200]);
        }
        $discovery = new WopiDiscovery(
            new MockHttpClient($responses),
            new ArrayAdapter(),
            new NullLogger(),
            $config ?? $this->config(),
        );

        $this->locks = new WopiLockStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $quota = $this->createStub(StorageQuotaService::class);
        $quota->method('getRemainingStorage')->willReturn($remainingBytes);
        $vectors = $this->createStub(VectorStorageFacade::class);

        return new WopiDocumentService(
            $files,
            $users,
            $this->tokens(),
            $discovery,
            $this->locks,
            new WopiFileUpdate($em, new DocumentThumbnailDispatcher($bus, $converter), $vectors, $quota, new NullLogger(), $dir),
            $config ?? $this->config(),
            $dir,
        );
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getDisplayName')->willReturn('Ada');

        return $user;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/wopi-'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }
}

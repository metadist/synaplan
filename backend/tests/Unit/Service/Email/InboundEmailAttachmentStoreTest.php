<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\File\FileStorageService;
use App\Service\Security\SsrfGuard;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InboundEmailAttachmentStoreTest extends TestCase
{
    private FileStorageService&MockObject $storage;
    private EntityManagerInterface&Stub $em;
    private StorageQuotaService&Stub $quota;

    /** @var list<File> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->storage = $this->createMock(FileStorageService::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof File) {
                $this->persisted[] = $entity;
            }
        });
        $this->quota = $this->createStub(StorageQuotaService::class);
        $this->quota->method('getRemainingStorage')->willReturn(FileStorageService::MAX_FILE_SIZE);
    }

    public function testStoresAllowedAttachmentAndLinksItToTheMessage(): void
    {
        $this->storage->expects(self::once())->method('storeRawContent')->with(
            'PDFBYTES',
            7,
            self::callback(static fn (string $name): bool => (bool) preg_match('/^invoice_[0-9a-f]{8}\.pdf$/', $name)),
            'application/pdf',
        )->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/invoice.pdf',
            'size' => 8,
            'mime' => 'application/pdf',
            'error' => null,
        ]);

        $client = new MockHttpClient([
            new MockResponse('PDFBYTES', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/pdf'],
            ]),
        ]);

        $message = $this->messageWithId(42);
        $message->setUserId(7);
        $message->setFile(0);

        $stored = $this->store($client)->attach($message, $this->user(), [[
            'filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
            'size' => 8,
            'url' => 'https://relay.example.com/files/invoice.pdf',
        ]]);

        self::assertCount(1, $stored);
        self::assertSame(1, $message->getFile());
        self::assertSame($stored[0], $message->getFiles()->first());
        self::assertSame('chat_attachment', $stored[0]->getSource());
        self::assertSame('pdf', $stored[0]->getFileType());
        self::assertSame('invoice.pdf', $stored[0]->getFileName());
        self::assertSame(42, $stored[0]->getMessageId());
        self::assertNull($stored[0]->getGroupKey());
        self::assertCount(1, $this->persisted);
    }

    public function testDuplicateFilenamesGetDistinctStorageBasenames(): void
    {
        $names = [];
        $this->storage->expects(self::exactly(2))->method('storeRawContent')->willReturnCallback(
            function (string $content, int $userId, string $filename, string $mime) use (&$names): array {
                $names[] = $filename;
                self::assertSame(7, $userId);
                self::assertSame('text/plain', $mime);

                return [
                    'success' => true,
                    'path' => '00/000/000007/2026/09/'.$filename,
                    'size' => strlen($content),
                    'mime' => $mime,
                    'error' => null,
                ];
            }
        );

        $client = new MockHttpClient([
            new MockResponse('one', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
            new MockResponse('two!', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]);

        $stored = $this->store($client)->attach($this->messageWithId(9), $this->user(), [
            ['filename' => 'notes.txt', 'url' => 'https://relay.example.com/a.txt'],
            ['filename' => 'notes.txt', 'url' => 'https://relay.example.com/b.txt'],
        ]);

        self::assertCount(2, $stored);
        self::assertSame('notes.txt', $stored[0]->getFileName());
        self::assertSame('notes.txt', $stored[1]->getFileName());
        self::assertCount(2, $names);
        self::assertNotSame($names[0], $names[1]);
        self::assertMatchesRegularExpression('/^notes_[0-9a-f]{8}\.txt$/', $names[0]);
        self::assertMatchesRegularExpression('/^notes_[0-9a-f]{8}\.txt$/', $names[1]);
    }

    public function testSkipsPrivateAddressesWithoutFetching(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('nope', ['http_code' => 200]);
        });

        $message = new Message();
        $message->setUserId(7);

        $stored = $this->store($client)->attach($message, $this->user(), [[
            'filename' => 'secret.pdf',
            'url' => 'http://127.0.0.1/secret.pdf',
        ]]);

        self::assertSame([], $stored);
        self::assertSame([], $requested);
        self::assertSame(0, $message->getFile());
    }

    public function testSkipsUnknownExtensionsWithoutFetching(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('MZ', ['http_code' => 200]);
        });

        $stored = $this->store($client)->attach(new Message(), $this->user(), [[
            'filename' => 'payload.exe',
            'url' => 'https://relay.example.com/payload.exe',
        ]]);

        self::assertSame([], $stored);
        self::assertSame([], $requested);
    }

    public function testSkipsOversizedDeclaredSizeWithoutFetching(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('x', ['http_code' => 200]);
        });

        $stored = $this->store($client)->attach(new Message(), $this->user(), [[
            'filename' => 'huge.pdf',
            'size' => FileStorageService::MAX_FILE_SIZE + 1,
            'url' => 'https://relay.example.com/huge.pdf',
        ]]);

        self::assertSame([], $stored);
        self::assertSame([], $requested);
    }

    public function testSkipsWhenQuotaIsExhaustedWithoutFetching(): void
    {
        $quota = $this->createStub(StorageQuotaService::class);
        $quota->method('getRemainingStorage')->willReturn(3);
        $this->storage->expects(self::never())->method('storeRawContent');
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('PDFBYTES', ['http_code' => 200]);
        });

        $stored = $this->store($client, $quota)->attach(new Message(), $this->user(), [[
            'filename' => 'invoice.pdf',
            'size' => 8,
            'url' => 'https://relay.example.com/invoice.pdf',
        ]]);

        self::assertSame([], $stored);
        self::assertSame([], $requested);
    }

    public function testOneBadAttachmentDoesNotBlockAGoodNeighbour(): void
    {
        $this->storage->expects(self::once())->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/notes.txt',
            'size' => 5,
            'mime' => 'text/plain',
            'error' => null,
        ]);

        $client = new MockHttpClient([
            new MockResponse('hello', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]),
        ]);

        $message = new Message();
        $message->setUserId(7);

        $stored = $this->store($client)->attach($message, $this->user(), [
            ['filename' => 'payload.exe', 'url' => 'https://relay.example.com/payload.exe'],
            ['filename' => 'notes.txt', 'url' => 'https://relay.example.com/notes.txt'],
        ]);

        self::assertCount(1, $stored);
        self::assertSame('notes.txt', $stored[0]->getFileName());
        self::assertSame(1, $message->getFile());
    }

    public function testRedirectToAPrivateAddressIsBlocked(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'],
            ]);
        });

        $stored = $this->store($client)->attach(new Message(), $this->user(), [[
            'filename' => 'invoice.pdf',
            'url' => 'https://public.example.com/go',
        ]]);

        self::assertSame([], $stored);
        self::assertSame(['https://public.example.com/go'], $requested);
    }

    public function testProtocolRelativeRedirectKeepsTheCurrentScheme(): void
    {
        $this->storage->expects(self::once())->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/invoice.pdf',
            'size' => 8,
            'mime' => 'application/pdf',
            'error' => null,
        ]);

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;
            if (str_contains($url, 'public.example.com')) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['location' => '//cdn.example.com/file.pdf'],
                ]);
            }

            return new MockResponse('PDFBYTES', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/pdf'],
            ]);
        });

        $stored = $this->store($client)->attach($this->messageWithId(1), $this->user(), [[
            'filename' => 'invoice.pdf',
            'url' => 'https://public.example.com/go',
        ]]);

        self::assertCount(1, $stored);
        self::assertSame([
            'https://public.example.com/go',
            'https://cdn.example.com/file.pdf',
        ], $requested);
    }

    public function testQueryOnlyRedirectKeepsTheCurrentPath(): void
    {
        $this->storage->expects(self::once())->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/invoice.pdf',
            'size' => 8,
            'mime' => 'application/pdf',
            'error' => null,
        ]);

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;
            if (!str_contains($url, 'token=')) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['location' => '?token=abc'],
                ]);
            }

            return new MockResponse('PDFBYTES', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/pdf'],
            ]);
        });

        $stored = $this->store($client)->attach($this->messageWithId(1), $this->user(), [[
            'filename' => 'invoice.pdf',
            'url' => 'https://relay.example.com/files/invoice.pdf',
        ]]);

        self::assertCount(1, $stored);
        self::assertSame([
            'https://relay.example.com/files/invoice.pdf',
            'https://relay.example.com/files/invoice.pdf?token=abc',
        ], $requested);
    }

    public function testPinsResolvedPublicIpsOnHostnameFetches(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::atLeastOnce())->method('isBlockedUrl')->willReturn(false);
        $guard->expects(self::atLeastOnce())->method('pinnedIps')->with('relay.example.com')->willReturn(['203.0.113.9']);

        $resolve = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$resolve): MockResponse {
            $resolve = $options['resolve'] ?? null;

            return new MockResponse('PDFBYTES', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/pdf'],
            ]);
        });

        $this->storage->expects(self::once())->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/invoice.pdf',
            'size' => 8,
            'mime' => 'application/pdf',
            'error' => null,
        ]);

        $stored = $this->store($client, null, $guard)->attach($this->messageWithId(1), $this->user(), [[
            'filename' => 'invoice.pdf',
            'url' => 'https://relay.example.com/files/invoice.pdf',
        ]]);

        self::assertCount(1, $stored);
        self::assertSame(['relay.example.com' => '203.0.113.9'], $resolve);
    }

    public function testStopsAfterTheAttachmentCountCap(): void
    {
        $this->storage->expects(self::exactly(10))->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '00/000/000007/2026/09/notes.txt',
            'size' => 1,
            'mime' => 'text/plain',
            'error' => null,
        ]);

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('x', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]);
        });

        $attachments = [];
        for ($i = 0; $i < 12; ++$i) {
            $attachments[] = [
                'filename' => 'notes.txt',
                'url' => 'https://relay.example.com/notes-'.$i.'.txt',
            ];
        }

        $stored = $this->store($client)->attach($this->messageWithId(1), $this->user(), $attachments);

        self::assertCount(10, $stored);
        self::assertCount(10, $requested);
    }

    public function testMissingUrlIsSkipped(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');

        $stored = $this->store(new MockHttpClient())->attach(new Message(), $this->user(), [[
            'filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
        ]]);

        self::assertSame([], $stored);
    }

    private function store(
        MockHttpClient $client,
        ?StorageQuotaService $quota = null,
        ?SsrfGuard $guard = null,
    ): InboundEmailAttachmentStore {
        return new InboundEmailAttachmentStore(
            $client,
            $guard ?? new SsrfGuard(),
            $this->storage,
            $this->em,
            new NullLogger(),
            $quota ?? $this->quota,
        );
    }

    private function user(int $id = 7): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function messageWithId(int $id): Message
    {
        $message = new Message();
        $message->setUserId(7);
        $ref = new \ReflectionProperty(Message::class, 'id');
        $ref->setValue($message, $id);

        return $message;
    }
}

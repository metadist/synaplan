<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\File\FileStorageService;
use App\Service\Security\SsrfGuard;
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
    }

    public function testStoresAllowedAttachmentAndLinksItToTheMessage(): void
    {
        $this->storage->expects(self::once())->method('storeRawContent')->with(
            'PDFBYTES',
            7,
            'invoice.pdf',
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

        $message = new Message();
        $message->setUserId(7);
        $message->setFile(0);

        $stored = $this->store($client)->attach($message, 7, [[
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
        self::assertNull($stored[0]->getGroupKey());
        self::assertCount(1, $this->persisted);
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

        $stored = $this->store($client)->attach($message, 7, [[
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

        $stored = $this->store($client)->attach(new Message(), 7, [[
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

        $stored = $this->store($client)->attach(new Message(), 7, [[
            'filename' => 'huge.pdf',
            'size' => FileStorageService::MAX_FILE_SIZE + 1,
            'url' => 'https://relay.example.com/huge.pdf',
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

        $stored = $this->store($client)->attach($message, 7, [
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

        $stored = $this->store($client)->attach(new Message(), 7, [[
            'filename' => 'invoice.pdf',
            'url' => 'https://public.example.com/go',
        ]]);

        self::assertSame([], $stored);
        self::assertSame(['https://public.example.com/go'], $requested);
    }

    public function testMissingUrlIsSkipped(): void
    {
        $this->storage->expects(self::never())->method('storeRawContent');

        $stored = $this->store(new MockHttpClient())->attach(new Message(), 7, [[
            'filename' => 'invoice.pdf',
            'content_type' => 'application/pdf',
        ]]);

        self::assertSame([], $stored);
    }

    private function store(MockHttpClient $client): InboundEmailAttachmentStore
    {
        return new InboundEmailAttachmentStore(
            $client,
            new SsrfGuard(),
            $this->storage,
            $this->em,
            new NullLogger(),
        );
    }
}

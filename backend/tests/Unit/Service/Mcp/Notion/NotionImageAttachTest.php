<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Service\File\FileStorageService;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\Notion\NotionImageAttach;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NotionImageAttachTest extends TestCase
{
    private const PAGE = '<image source="notion-file-block://a/b?name=shot.png" />';

    private int $fetches = 0;

    private string $imageUrl = 'https://8.8.8.8/shot.png';

    private string $imageBody = "\x89PNG\r\n\x1a\n";

    private int $imageStatus = 200;

    /** @var array<string, string> */
    private array $imageHeaders = ['content-type' => 'image/png', 'content-length' => '8'];

    public function testReadsTheFileReferenceAndStopsAfterTheFirstImage(): void
    {
        $pages = [];
        for ($i = 1; $i <= 6; ++$i) {
            $pages[] = ['id' => 'p'.$i, 'title' => 'Page '.$i, 'type' => 'page'];
        }
        $result = $this->attach($pages, $this->page(1));

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame(1, $this->fetches);
        self::assertSame('shot.png', $result->files[0]['name'] ?? null);
        self::assertSame('image', $result->files[0]['type'] ?? null);
        self::assertStringContainsString('Page 1', (string) $result->text);
    }

    public function testAnImageTagIsUsedWhenThePageMentionsOtherFileBlocks(): void
    {
        $markdown = "The spec uses notion-file-block://docs/example?name=diagram.png.\n"
            .'<image source="notion-file-block://a/b?space_id=1&amp;name=shot.png" />';
        $result = $this->attach([['id' => 'p1', 'title' => 'Synaplan', 'type' => 'page']], $markdown);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame('shot.png', $result->files[0]['name'] ?? null);
    }

    public function testTheFetchJsonEnvelopeStillYieldsTheImage(): void
    {
        $envelope = json_encode([
            'metadata' => ['type' => 'page'],
            'title' => 'Synaplan',
            'text' => "Intro\n".'<image source="notion-file-block://a/b?space_id=1&name=shot.png" />'."\n",
        ], JSON_THROW_ON_ERROR);
        $result = $this->attach([['id' => 'p1', 'title' => 'Synaplan', 'type' => 'page']], $envelope);

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame('shot.png', $result->files[0]['name'] ?? null);
    }

    public function testStopsAtThePageLimitWhenNoPageHasAnImage(): void
    {
        $pages = [];
        for ($i = 1; $i <= 6; ++$i) {
            $pages[] = ['id' => 'p'.$i, 'title' => 'Page '.$i, 'type' => 'page'];
        }
        $result = $this->attach($pages, 'No pictures here.');

        self::assertTrue($result->isSuccessful(), (string) $result->error);
        self::assertSame([], $result->files);
        self::assertSame(NotionImageAttach::MAX_PAGES, $this->fetches);
        self::assertStringContainsString('Looked through 5 of 5 pages and found no image', (string) $result->text);
    }

    public function testANonImageBodyIsNotAttached(): void
    {
        $this->imageBody = '<html>not an image</html>';
        $this->imageHeaders = ['content-type' => 'text/html', 'content-length' => (string) strlen($this->imageBody)];

        $result = $this->attach([['id' => 'p1', 'title' => 'Page', 'type' => 'page']]);

        self::assertTrue($result->isReportableFailure());
        self::assertStringContainsString('not an image, so nothing was attached', (string) $result->error);
    }

    public function testAnOversizedImageIsNotAttached(): void
    {
        $this->imageBody = str_repeat('A', NotionImageAttach::MAX_BYTES + 1);
        $this->imageHeaders = ['content-type' => 'image/png', 'content-length' => (string) strlen($this->imageBody)];

        $result = $this->attach([['id' => 'p1', 'title' => 'Page', 'type' => 'page']]);

        self::assertTrue($result->isReportableFailure());
        self::assertStringContainsString('larger than 10 MB, so nothing was attached', (string) $result->error);
    }

    public function testAPrivateDownloadLinkIsNotFetched(): void
    {
        $this->imageUrl = 'https://127.0.0.1/shot.png';

        $result = $this->attach([['id' => 'p1', 'title' => 'Page', 'type' => 'page']]);

        self::assertTrue($result->isReportableFailure());
        self::assertStringContainsString('not a public address, so nothing was attached', (string) $result->error);
    }

    /**
     * @param list<array<string, string>> $pages
     */
    private function attach(array $pages, string $markdown = self::PAGE): NodeResult
    {
        $this->fetches = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($markdown): MockResponse {
            if ('GET' === $method) {
                return new MockResponse($this->imageBody, [
                    'http_code' => $this->imageStatus,
                    'response_headers' => $this->imageHeaders,
                ]);
            }
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $rpc = is_array($body) ? (string) ($body['method'] ?? '') : '';
            $tool = is_array($body['params'] ?? null) ? (string) ($body['params']['name'] ?? '') : '';
            if ('notion-fetch' === $tool) {
                ++$this->fetches;
            }
            $text = match ($tool) {
                'notion-fetch' => $markdown,
                'notion-get-file-download-urls' => '{"files":[{"url":"'.$this->imageUrl.'"}]}',
                default => '',
            };

            return new MockResponse((string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => 'tools/call' === $rpc
                    ? ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]
                    : ['protocolVersion' => '2025-03-26'],
            ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });

        $config = $this->createMock(ConfigRepository::class);
        $client = new McpClient(
            $http,
            new SsrfGuard(),
            new EncryptionService('test-secret', new NullLogger()),
            new McpClientConfig($config),
            new NullLogger(),
        );
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '7/shot.png',
            'size' => 8,
            'mime' => 'image/png',
            'error' => null,
        ]);
        $service = new NotionImageAttach(
            $client,
            $http,
            new SsrfGuard(),
            $storage,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
        $server = new McpServerConfig();
        $server->setUserId(7)->setName('Notion')->setUrl('https://mcp.notion.com/mcp')->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 3);

        return $service->attach($server, 'notion-get-file-download-urls', [
            'pages' => json_encode(['results' => $pages]),
        ], 7);
    }

    private function page(int $withImage): string
    {
        return 1 === $withImage ? self::PAGE : 'No pictures here.';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Research\Eval\B1Importer;
use App\Service\Research\Eval\B1PlusAdapter;
use App\Service\Research\Eval\B4Adapter;
use App\Service\Research\Eval\CorpusFacts;
use App\Service\Research\Eval\CorpusItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExternalArmTest extends TestCase
{
    public function testB1PlusSendsNothingWithoutAKey(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            $this->fail('A request was sent.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing was sent.');

        (new B1PlusAdapter($client))->answer($this->item(), '', '', null);
    }

    public function testB1PlusReadsTheGatewayText(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'Regulation 2024/1689.']],
        ], JSON_THROW_ON_ERROR)));

        $text = (new B1PlusAdapter($client))->answer($this->item(), 'http://gateway.test', 'key', null);

        $this->assertSame('Regulation 2024/1689.', $text);
    }

    public function testB1PlusSendsTheGatewayModelWhenGiven(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('http://gateway.test/v1/messages', $url);
            $this->assertSame('eval-model', $body['model'] ?? null);

            return new MockResponse(json_encode([
                'content' => [['type' => 'text', 'text' => 'Answer.']],
            ], JSON_THROW_ON_ERROR));
        });

        $text = (new B1PlusAdapter($client))->answer($this->item(), 'http://gateway.test/', 'key', null, 'eval-model');

        $this->assertSame('Answer.', $text);
    }

    public function testB1PlusDeclaresWebSearchAndKeepsCitationUrls(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([['type' => 'web_search_20250305', 'name' => 'web_search']], $body['tools'] ?? null);

            return new MockResponse(json_encode([
                'content' => [
                    ['type' => 'server_tool_use', 'name' => 'web_search'],
                    ['type' => 'text', 'text' => 'It is 2024/1689.', 'citations' => [['url' => 'https://eur-lex.example/a'], ['url' => 'https://eur-lex.example/a']]],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $text = (new B1PlusAdapter($client))->answer($this->item(), 'http://gateway.test', 'key', null, null, true);

        $this->assertSame('It is 2024/1689. ([source](https://eur-lex.example/a))', $text);
    }

    public function testB1PlusSendsNoToolsWithoutWebSearch(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('tools', $body);

            return new MockResponse(json_encode(['content' => [['type' => 'text', 'text' => 'Answer.']]], JSON_THROW_ON_ERROR));
        });

        $this->assertSame('Answer.', (new B1PlusAdapter($client))->answer($this->item(), 'http://gateway.test', 'key', null));
    }

    public function testB1PlusExplainsANonJsonAnswer(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The gateway did not answer with JSON (status 502).');

        (new B1PlusAdapter($client))->answer($this->item(), 'http://gateway.test', 'key', null);
    }

    public function testB4SendsNothingWithoutAnEndpoint(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            $this->fail('A request was sent.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing was sent.');

        (new B4Adapter($client))->answer($this->item(), '');
    }

    public function testAnImportMapsIdsToReportText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        $this->assertIsString($path);
        file_put_contents($path, json_encode([
            'items' => [['id' => 'fact-01', 'text' => '2024/1689']],
        ], JSON_THROW_ON_ERROR));

        try {
            $loaded = (new B1Importer())->load($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(['fact-01' => '2024/1689'], $loaded);
    }

    private function item(): CorpusItem
    {
        return new CorpusItem('fact-01', 'current', 'en', 'Question', 'standard', true, false, new CorpusFacts([], [], []), true, null);
    }
}

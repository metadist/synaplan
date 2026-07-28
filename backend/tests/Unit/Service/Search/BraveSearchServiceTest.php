<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Service\Search\BraveSearchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BraveSearchServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testSearchSendsSearchLangAndUiLangForGerman(): void
    {
        $capturedQuery = null;

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'web' => [
                'results' => [
                    [
                        'title' => 'Synaplan',
                        'url' => 'https://example.com',
                        'description' => 'Open Source KI',
                    ],
                ],
            ],
            'query' => ['original' => 'Synaplan'],
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.search.brave.com/res/v1/web/search',
                $this->callback(function (array $options) use (&$capturedQuery): bool {
                    $capturedQuery = $options['query'] ?? null;

                    return true;
                })
            )
            ->willReturn($response);

        $service = $this->createService();
        $service->search('Synaplan Open Source', [
            'search_lang' => 'de',
            'country' => 'de',
        ]);

        $this->assertIsArray($capturedQuery);
        $this->assertSame('de', $capturedQuery['search_lang']);
        $this->assertSame('de-DE', $capturedQuery['ui_lang']);
        $this->assertSame('DE', $capturedQuery['country']);
    }

    public function testSearchAcceptsExplicitUiLangLocale(): void
    {
        $capturedQuery = null;

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'web' => ['results' => []],
            'query' => ['original' => 'test'],
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.search.brave.com/res/v1/web/search',
                $this->callback(function (array $options) use (&$capturedQuery): bool {
                    $capturedQuery = $options['query'] ?? null;

                    return true;
                })
            )
            ->willReturn($response);

        $service = $this->createService();
        $service->search('test', [
            'search_lang' => 'tr',
            'ui_lang' => 'tr-TR',
            'country' => 'tr',
        ]);

        $this->assertIsArray($capturedQuery);
        $this->assertSame('tr', $capturedQuery['search_lang']);
        $this->assertSame('tr-TR', $capturedQuery['ui_lang']);
    }

    private function createService(): BraveSearchService
    {
        return new BraveSearchService(
            $this->httpClient,
            new NullLogger(),
            'test-api-key',
            'https://api.search.brave.com/res/v1',
            true,
            10,
            'us',
            'en',
        );
    }
}

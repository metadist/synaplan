<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Repository\ConfigRepository;
use App\Service\Message\RouterClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RouterClientTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createClient(MockHttpClient $httpClient): RouterClient
    {
        return new RouterClient($httpClient, $this->configRepository, $this->logger);
    }

    public function testClassifyReturnsNullWhenDisabled(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnMap([
                [0, 'ROUTER', 'ENABLED', null],
            ]);

        $client = $this->createClient(new MockHttpClient());
        $result = $client->classify('Hello world');

        $this->assertNull($result);
    }

    public function testClassifyReturnsResultWhenEnabled(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('ENABLED' === $key) {
                    return 'true';
                }
                if ('SERVICE_URL' === $key) {
                    return 'http://router:8000';
                }
                if ('TIMEOUT_MS' === $key) {
                    return '100';
                }

                return null;
            });

        $responseBody = json_encode([
            'use_case' => 'text_chat',
            'confidence' => 0.95,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v1.0.0',
            'latency_ms' => 2.1,
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($responseBody, ['http_code' => 200]),
        ]);

        $client = $this->createClient($httpClient);
        $result = $client->classify('Hello world');

        $this->assertNotNull($result);
        $this->assertEquals('text_chat', $result['use_case']);
        $this->assertEquals(0.95, $result['confidence']);
        $this->assertFalse($result['is_compound']);
    }

    public function testClassifyReturnsNullOnTimeout(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('ENABLED' === $key) {
                    return 'true';
                }
                if ('SERVICE_URL' === $key) {
                    return 'http://router:8000';
                }
                if ('TIMEOUT_MS' === $key) {
                    return '100';
                }

                return null;
            });

        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Timeout']),
        ]);

        $client = $this->createClient($httpClient);
        $result = $client->classify('Hello world');

        $this->assertNull($result);
    }

    public function testCircuitBreakerOpensAfterThreshold(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('ENABLED' === $key) {
                    return 'true';
                }
                if ('SERVICE_URL' === $key) {
                    return 'http://router:8000';
                }
                if ('TIMEOUT_MS' === $key) {
                    return '100';
                }
                if ('CIRCUIT_BREAKER_THRESHOLD' === $key) {
                    return '3';
                }
                if ('CIRCUIT_BREAKER_RESET_S' === $key) {
                    return '60';
                }

                return null;
            });

        $httpClient = new MockHttpClient(array_fill(0, 5, new MockResponse('', ['error' => 'Connection refused'])));

        $client = $this->createClient($httpClient);

        $client->classify('msg 1');
        $client->classify('msg 2');
        $client->classify('msg 3');

        // After 3 failures, circuit should be open — 4th call returns null without HTTP request
        $result = $client->classify('msg 4');
        $this->assertNull($result);
    }

    public function testCompoundClassification(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $key): ?string {
                if ('ENABLED' === $key) {
                    return 'true';
                }
                if ('SERVICE_URL' === $key) {
                    return 'http://router:8000';
                }
                if ('TIMEOUT_MS' === $key) {
                    return '100';
                }

                return null;
            });

        $responseBody = json_encode([
            'use_case' => 'compound_research_image',
            'confidence' => 0.89,
            'is_compound' => true,
            'steps' => [
                ['id' => 'step_1', 'capability' => 'CHAT', 'web_search' => true],
                ['id' => 'step_2', 'capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
            ],
            'model_version' => 'v1.2.0',
            'latency_ms' => 3.0,
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($responseBody, ['http_code' => 200]),
        ]);

        $client = $this->createClient($httpClient);
        $result = $client->classify('Recherchiere den Goldpreis und generiere ein Bild davon');

        $this->assertNotNull($result);
        $this->assertTrue($result['is_compound']);
        $this->assertCount(2, $result['steps']);
        $this->assertEquals('CHAT', $result['steps'][0]['capability']);
        $this->assertEquals('IMAGE_GENERATION', $result['steps'][1]['capability']);
    }

    public function testGetConfidenceThreshold(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnMap([
                [0, 'ROUTER', 'CONFIDENCE_THRESHOLD', '0.85'],
            ]);

        $client = $this->createClient(new MockHttpClient());
        $this->assertEquals(0.85, $client->getConfidenceThreshold());
    }

    public function testGetConfidenceThresholdDefault(): void
    {
        $this->configRepository->method('getValue')
            ->willReturn(null);

        $client = $this->createClient(new MockHttpClient());
        $this->assertEquals(0.80, $client->getConfidenceThreshold());
    }
}

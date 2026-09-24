<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ModelDiscovery;

use App\Service\ModelDiscovery\ModelDiscoveryUnavailableException;
use App\Service\ModelDiscovery\OpenRouterModelSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouterModelSourceTest extends TestCase
{
    public function testHttp500RaisesUnavailable(): void
    {
        $source = new OpenRouterModelSource(new MockHttpClient([
            new MockResponse('upstream error', ['http_code' => 500]),
        ]));

        $this->expectException(ModelDiscoveryUnavailableException::class);
        $this->expectExceptionMessage('HTTP 500');
        $source->fetch();
    }

    public function testInvalidJsonRaisesUnavailable(): void
    {
        $source = new OpenRouterModelSource(new MockHttpClient([
            new MockResponse('not-json{', ['http_code' => 200]),
        ]));

        $this->expectException(ModelDiscoveryUnavailableException::class);
        $this->expectExceptionMessage('not valid JSON');
        $source->fetch();
    }

    public function testEmptyDataRaisesUnavailable(): void
    {
        $source = new OpenRouterModelSource(new MockHttpClient([
            new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]));

        $this->expectException(ModelDiscoveryUnavailableException::class);
        $this->expectExceptionMessage('non-empty "data" array');
        $source->fetch();
    }

    public function testEntryMissingCreatedRaisesUnavailable(): void
    {
        $payload = json_encode([
            'data' => [
                ['id' => 'anthropic/claude-opus-5.5', 'name' => 'broken'],
            ],
        ], JSON_THROW_ON_ERROR);

        $source = new OpenRouterModelSource(new MockHttpClient([
            new MockResponse($payload, ['http_code' => 200]),
        ]));

        $this->expectException(ModelDiscoveryUnavailableException::class);
        $this->expectExceptionMessage('no int "created"');
        $source->fetch();
    }

    public function testValidPayloadParsesPricesPer1M(): void
    {
        $payload = json_encode([
            'data' => [
                [
                    'id' => 'anthropic/claude-opus-5.5',
                    'name' => 'Claude Opus 5.5',
                    'created' => 1790094732,
                    'pricing' => [
                        'prompt' => '0.000004',
                        'completion' => '0.00002',
                        'input_cache_read' => '0.0000002',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $models = (new OpenRouterModelSource(new MockHttpClient([
            new MockResponse($payload, ['http_code' => 200]),
        ])))->fetch();

        $this->assertCount(1, $models);
        $this->assertSame('anthropic/claude-opus-5.5', $models[0]->openRouterId);
        $this->assertSame('anthropic', $models[0]->vendor);
        $this->assertSame(1790094732, $models[0]->created->getTimestamp());
        $this->assertEqualsWithDelta(4.0, $models[0]->priceInPer1M, 0.0001);
        $this->assertEqualsWithDelta(20.0, $models[0]->priceOutPer1M, 0.0001);
        $this->assertEqualsWithDelta(0.2, $models[0]->cacheReadPer1M, 0.0001);
    }
}

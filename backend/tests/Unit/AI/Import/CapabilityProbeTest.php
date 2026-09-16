<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Import;

use App\AI\Import\CapabilityProbe;
use App\AI\Import\ProbeResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CapabilityProbeTest extends TestCase
{
    public function testBothCapabilitiesAnswerOk(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"choices":[]}', ['http_code' => 200]),
            new MockResponse('{"data":[]}', ['http_code' => 200]),
        ]);

        $result = (new CapabilityProbe($client))->probe($this->endpoint(), 'some-model');

        self::assertSame(ProbeResult::OK, $result->chat);
        self::assertSame(ProbeResult::OK, $result->embeddings);
    }

    public function testChatErrorIsFailEmbeddingsOk(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":"not a chat model"}', ['http_code' => 400]),
            new MockResponse('{"data":[]}', ['http_code' => 200]),
        ]);

        $result = (new CapabilityProbe($client))->probe($this->endpoint(), 'bge-m3');

        self::assertSame(ProbeResult::FAIL, $result->chat);
        self::assertSame(ProbeResult::OK, $result->embeddings);
        // An embeddings-only model: probe turns the "chat" guess into "vectorize".
        self::assertSame(['vectorize'], $result->overrideTags(['chat']));
    }

    public function testTransportErrorIsFail(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('timeout');
        });

        $result = (new CapabilityProbe($client))->probe($this->endpoint(), 'ghost');

        self::assertSame(ProbeResult::FAIL, $result->chat);
        self::assertSame(ProbeResult::FAIL, $result->embeddings);
        // Nothing answered: keep the original guess rather than emptying it.
        self::assertSame(['chat'], $result->overrideTags(['chat']));
    }

    public function testSkippedResultLeavesGuessUntouched(): void
    {
        $result = ProbeResult::skipped();

        self::assertSame(ProbeResult::SKIPPED, $result->chat);
        self::assertSame(['chat'], $result->overrideTags(['chat']));
        self::assertSame(['rerank'], $result->overrideTags(['rerank']));
    }

    public function testOverrideAddsVectorizeWithoutDuplicating(): void
    {
        $result = new ProbeResult(ProbeResult::OK, ProbeResult::OK, 5);

        self::assertSame(['chat', 'vectorize'], $result->overrideTags(['chat']));
        self::assertSame(['vectorize'], $result->overrideTags(['vectorize']));
    }

    /**
     * @return array{base_url: string, api_key: string, headers: array<string, string>}
     */
    private function endpoint(): array
    {
        return ['base_url' => 'http://vllm.test/v1', 'api_key' => 'sk-test', 'headers' => []];
    }
}

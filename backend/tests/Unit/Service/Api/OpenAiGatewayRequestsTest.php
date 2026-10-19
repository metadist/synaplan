<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\Service\Api\OpenAiEmbeddingsRequest;
use App\Service\Api\OpenAiGatewayException;
use App\Service\Api\OpenAiImageGenerationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiGatewayRequestsTest extends TestCase
{
    public function testEmbeddingsDefaults(): void
    {
        $request = OpenAiEmbeddingsRequest::fromBody(['input' => 'hello', 'model' => ' bge-m3 ']);

        self::assertSame(['hello'], $request->inputs);
        self::assertSame('bge-m3', $request->model);
        self::assertSame('float', $request->encodingFormat);
        self::assertNull($request->dimensions);
    }

    public function testEmbeddingsKeepOrderAndOptions(): void
    {
        $request = OpenAiEmbeddingsRequest::fromBody(['input' => ['a', 'b'], 'encoding_format' => 'base64', 'dimensions' => 256]);

        self::assertSame(['a', 'b'], $request->inputs);
        self::assertNull($request->model);
        self::assertSame('base64', $request->encodingFormat);
        self::assertSame(256, $request->dimensions);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidEmbeddings')]
    public function testEmbeddingsRejectMalformedBodies(array $body, string $code): void
    {
        try {
            OpenAiEmbeddingsRequest::fromBody($body);
            self::fail('Expected OpenAiGatewayException');
        } catch (OpenAiGatewayException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidEmbeddings(): iterable
    {
        yield 'missing input' => [[], 'invalid_input'];
        yield 'empty list' => [['input' => []], 'invalid_input'];
        yield 'blank string' => [['input' => '  '], 'invalid_input'];
        yield 'token ids' => [['input' => [[1, 2]]], 'invalid_input'];
        yield 'map instead of list' => [['input' => ['a' => 'x']], 'invalid_input'];
        yield 'too many inputs' => [['input' => array_fill(0, OpenAiEmbeddingsRequest::MAX_INPUTS + 1, 'x')], 'invalid_input'];
        yield 'too long' => [['input' => str_repeat('x', OpenAiEmbeddingsRequest::MAX_INPUT_CHARS + 1)], 'invalid_input'];
        yield 'bad format' => [['input' => 'x', 'encoding_format' => 'hex'], 'invalid_encoding_format'];
        yield 'zero dimensions' => [['input' => 'x', 'dimensions' => 0], 'invalid_dimensions'];
        yield 'string dimensions' => [['input' => 'x', 'dimensions' => '256'], 'invalid_dimensions'];
        yield 'model not a string' => [['input' => 'x', 'model' => 5], 'invalid_model'];
    }

    public function testImageDefaults(): void
    {
        $request = OpenAiImageGenerationRequest::fromBody(['prompt' => 'a cat']);

        self::assertSame('a cat', $request->prompt);
        self::assertNull($request->model);
        self::assertSame(1, $request->n);
        self::assertSame('1024x1024', $request->size);
        self::assertNull($request->quality);
        self::assertSame('url', $request->responseFormat);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidImages')]
    public function testImagesRejectMalformedBodies(array $body, string $code): void
    {
        try {
            OpenAiImageGenerationRequest::fromBody($body);
            self::fail('Expected OpenAiGatewayException');
        } catch (OpenAiGatewayException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidImages(): iterable
    {
        yield 'missing prompt' => [[], 'invalid_prompt'];
        yield 'blank prompt' => [['prompt' => ' '], 'invalid_prompt'];
        yield 'n zero' => [['prompt' => 'x', 'n' => 0], 'invalid_n'];
        yield 'n too big' => [['prompt' => 'x', 'n' => OpenAiImageGenerationRequest::MAX_IMAGES + 1], 'invalid_n'];
        yield 'size garbage' => [['prompt' => 'x', 'size' => 'huge'], 'invalid_size'];
        yield 'quality garbage' => [['prompt' => 'x', 'quality' => 'ultra'], 'invalid_quality'];
        yield 'format garbage' => [['prompt' => 'x', 'response_format' => 'png'], 'invalid_response_format'];
    }
}

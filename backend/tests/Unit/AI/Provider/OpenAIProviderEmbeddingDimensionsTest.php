<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #985 — OpenAIProvider used to hard-code
 * `dimensions: 1536` on every v3 embedding call AND lie about
 * `text-embedding-3-large`'s native output via `getDimensions()`.
 * That mismatch was the root cause of memory data loss after a
 * VECTORIZE switch: the catalog metadata said 3072 → Qdrant
 * collection was recreated at 3072 → real vectors came back at
 * 1536 → every upsert returned HTTP 400 → memories gone.
 *
 * These tests pin:
 *   - `getDimensions()` reports the TRUE native output per model.
 *   - `buildEmbeddingParams()` (the new helper) never injects a
 *     default `dimensions` and only forwards explicit caller
 *     overrides on v3 models.
 */
final class OpenAIProviderEmbeddingDimensionsTest extends TestCase
{
    public function testGetDimensionsReportsNativeOutputPerModel(): void
    {
        $provider = $this->makeProvider();

        // text-embedding-3-large is the regression — used to claim
        // 1536 because the provider forced truncation. It must
        // report the true 3072 native output now.
        self::assertSame(3072, $provider->getDimensions('text-embedding-3-large'));
        self::assertSame(1536, $provider->getDimensions('text-embedding-3-small'));
        self::assertSame(1536, $provider->getDimensions('text-embedding-ada-002'));
        self::assertSame(1536, $provider->getDimensions('totally-unknown-model'));
    }

    public function testBuildEmbeddingParamsDoesNotForceDimensionsByDefault(): void
    {
        $params = $this->invokeBuildEmbeddingParams('text-embedding-3-large', 'hello', []);

        self::assertSame('text-embedding-3-large', $params['model']);
        self::assertSame('hello', $params['input']);
        self::assertArrayNotHasKey(
            'dimensions',
            $params,
            'Default v3 embeds must NOT carry a `dimensions` override — that hardcode caused #985.',
        );
    }

    public function testBuildEmbeddingParamsForwardsExplicitOverrideOnV3Models(): void
    {
        $params = $this->invokeBuildEmbeddingParams(
            'text-embedding-3-large',
            'hello',
            ['dimensions' => 1024],
        );

        self::assertSame(1024, $params['dimensions']);
    }

    public function testBuildEmbeddingParamsIgnoresExplicitOverrideOnLegacyModels(): void
    {
        // ada-002 doesn't support the `dimensions` parameter at all —
        // the OpenAI API rejects it. The helper must drop the override
        // silently for non-v3 models so the request still succeeds.
        $params = $this->invokeBuildEmbeddingParams(
            'text-embedding-ada-002',
            'hello',
            ['dimensions' => 1024],
        );

        self::assertArrayNotHasKey('dimensions', $params);
    }

    public function testBuildEmbeddingParamsIgnoresEmptyOverride(): void
    {
        // Test fixtures often pass `['dimensions' => 0]` or a non-int
        // value when the caller doesn't care about override. Treat
        // that as "use native" rather than forwarding a garbage value.
        $params = $this->invokeBuildEmbeddingParams(
            'text-embedding-3-small',
            'hello',
            ['dimensions' => 0],
        );

        self::assertArrayNotHasKey('dimensions', $params);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function invokeBuildEmbeddingParams(string $model, string $text, array $options): array
    {
        $reflection = new \ReflectionClass(OpenAIProvider::class);
        $method = $reflection->getMethod('buildEmbeddingParams');
        $method->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();

        return $method->invoke($instance, $model, $text, $options);
    }

    private function makeProvider(): OpenAIProvider
    {
        // OpenAIProvider's getDimensions() is a pure static-style
        // lookup that doesn't read any state; constructing without
        // the API key path keeps the test hermetic.
        return (new \ReflectionClass(OpenAIProvider::class))->newInstanceWithoutConstructor();
    }
}

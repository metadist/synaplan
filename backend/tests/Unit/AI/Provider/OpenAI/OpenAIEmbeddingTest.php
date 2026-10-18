<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIEmbedding;
use App\Tests\Characterization\Support\OpenAIFixtures;
use OpenAI\Responses\Embeddings\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;

final class OpenAIEmbeddingTest extends TestCase
{
    use OpenAIApiDouble;

    public function testTheWidthIsTruncatedOnlyWhereTheCallerAsksOnAVersionThreeModel(): void
    {
        $vectors = static fn (int $count) => OpenAIFixtures::json(CreateResponse::fake(['data' => array_map(static fn (int $i): array => ['object' => 'embedding', 'index' => $i, 'embedding' => [0.5, $i]], range(0, $count - 1)), 'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3]], null, OverrideStrategy::Replace)->toArray());
        $embedding = new OpenAIEmbedding($this->api([$vectors(1), $vectors(2)]));

        $one = $embedding->embed('Hello', ['model' => 'text-embedding-3-large', 'dimensions' => 1024]);
        $batch = $embedding->embedBatch(['Hello', 'World'], ['model' => 'text-embedding-ada-002', 'dimensions' => 256]);

        self::assertSame([[0.5, 0], [[0.5, 0], [0.5, 1]], 3], [$one['embedding'], $batch['embeddings'], $one['usage']['total_tokens']]);
        [$first, $second] = $this->sdk->requests();
        self::assertSame([1024, false], [$first['body']['dimensions'], isset($second['body']['dimensions'])]);
    }

    public function testTheNativeWidthPerModel(): void
    {
        $embedding = new OpenAIEmbedding($this->api());

        self::assertSame([1536, 3072, 1536], [$embedding->getDimensions('text-embedding-3-small'), $embedding->getDimensions('text-embedding-3-large'), $embedding->getDimensions('text-embedding-ada-002')]);
    }
}

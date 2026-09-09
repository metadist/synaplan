<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Import;

use App\AI\Import\ModelTagGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelTagGuesserTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('names')]
    public function testGuess(string $id, array $expected): void
    {
        self::assertSame($expected, (new ModelTagGuesser())->guess($id));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function names(): iterable
    {
        // Embeddings
        yield 'bge-m3' => ['BAAI/bge-m3', ['vectorize']];
        yield 'e5 large' => ['intfloat/e5-large-v2', ['vectorize']];
        yield 'nomic embed' => ['nomic-embed-text', ['vectorize']];
        yield 'arctic-embed' => ['snowflake-arctic-embed2', ['vectorize']];
        yield 'minilm' => ['all-minilm', ['vectorize']];
        yield 'gte' => ['Alibaba-NLP/gte-large', ['vectorize']];
        yield 'mxbai' => ['mxbai-embed-large', ['vectorize']];
        yield 'openai embedding' => ['text-embedding-3-large', ['vectorize']];

        // Rerank wins over the embedding keyword it also contains
        yield 'bge reranker' => ['BAAI/bge-reranker-v2-m3', ['rerank']];
        yield 'cohere rerank' => ['rerank-v3.5', ['rerank']];
        yield 'jina reranker' => ['jina-reranker-v2-base-multilingual', ['rerank']];

        // Speech to text
        yield 'whisper' => ['whisper-large-v3', ['sound2text']];
        yield 'parakeet' => ['nvidia/parakeet-tdt', ['sound2text']];

        // Text to speech
        yield 'kokoro' => ['kokoro-82m', ['text2sound']];
        yield 'orpheus' => ['orpheus-tts', ['text2sound']];
        yield 'generic tts' => ['some-tts-model', ['text2sound']];
        yield 'speech' => ['speecht5', ['text2sound']];

        // Image generation
        yield 'flux' => ['black-forest-labs/FLUX.1-dev', ['text2pic']];
        yield 'sdxl' => ['stabilityai/sdxl-turbo', ['text2pic']];
        yield 'stable diffusion' => ['stable-diffusion-3.5', ['text2pic']];
        yield 'dall-e' => ['dall-e-3', ['text2pic']];

        // Vision chat -> chat + pic2text
        yield 'pixtral' => ['mistralai/Pixtral-12B-2409', ['chat', 'pic2text']];
        yield 'llava' => ['llava:13b', ['chat', 'pic2text']];
        yield 'qwen vl' => ['Qwen/Qwen2.5-VL-7B-Instruct', ['chat', 'pic2text']];
        yield 'gemma 3' => ['google/gemma-3-27b-it', ['chat', 'pic2text']];
        yield 'llama vision' => ['meta-llama/Llama-3.2-11B-Vision', ['chat', 'pic2text']];

        // Plain chat fallback
        yield 'qwen3' => ['Qwen/Qwen3-32B', ['chat']];
        yield 'llama' => ['meta-llama/Llama-3.3-70B-Instruct', ['chat']];
        yield 'gpt-4o' => ['gpt-4o', ['chat']];
        yield 'mistral' => ['mistralai/Mistral-Small-3', ['chat']];
        yield 'empty' => ['', ['chat']];
    }
}

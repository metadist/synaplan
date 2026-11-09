<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Stream;

use App\AI\Stream\AnthropicStreamDecoder;
use App\AI\Stream\DecodedEvent;
use App\AI\Stream\GeminiStreamDecoder;
use App\AI\Stream\OllamaNdjsonDecoder;
use App\AI\Stream\OpenAiChatCompletionsDecoder;
use App\AI\Stream\StreamDecoderInterface;
use PHPUnit\Framework\TestCase;

final class StreamDecoderSplitFeedTest extends TestCase
{
    private const ANTHROPIC = "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\nevent: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":1}}\n\n";

    private const OPENAI = "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n";

    private const GEMINI = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hi\"}]},\"finishReason\":\"STOP\"}]}\n";

    private const OLLAMA = "{\"message\":{\"content\":\"Hi\"},\"done\":false}\n{\"message\":{\"content\":\"\"},\"done\":true,\"done_reason\":\"stop\"}\n";

    public function testTwoHundredSeedsMatchTheWholeBody(): void
    {
        $cases = [
            [new AnthropicStreamDecoder(false), self::ANTHROPIC],
            [new OpenAiChatCompletionsDecoder(), self::OPENAI],
            [new GeminiStreamDecoder(), self::GEMINI],
            [new OllamaNdjsonDecoder(), self::OLLAMA],
        ];
        foreach ($cases as [$decoder, $body]) {
            $want = $this->kinds($decoder, $body);
            for ($seed = 1; $seed <= 200; ++$seed) {
                mt_srand($seed);
                $cut = mt_rand(0, strlen($body));
                $fresh = $this->fresh($decoder);
                $got = array_merge(
                    $this->kindsOf($fresh->feed(substr($body, 0, $cut))),
                    $this->kindsOf($fresh->feed(substr($body, $cut))),
                    $this->kindsOf($fresh->finish()),
                );
                self::assertSame($want, $got, $decoder::class.' seed '.$seed);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function kinds(StreamDecoderInterface $decoder, string $body): array
    {
        return array_merge($this->kindsOf($decoder->feed($body)), $this->kindsOf($decoder->finish()));
    }

    /**
     * @param list<DecodedEvent> $chunks
     *
     * @return list<string>
     */
    private function kindsOf(array $chunks): array
    {
        return array_map(static function (DecodedEvent $chunk): string {
            $data = is_scalar($chunk->data) || null === $chunk->data ? (string) $chunk->data : json_encode($chunk->data);

            return $chunk->kind.':'.$data;
        }, $chunks);
    }

    private function fresh(StreamDecoderInterface $decoder): StreamDecoderInterface
    {
        return match ($decoder::class) {
            AnthropicStreamDecoder::class => new AnthropicStreamDecoder(false),
            OpenAiChatCompletionsDecoder::class => new OpenAiChatCompletionsDecoder(),
            GeminiStreamDecoder::class => new GeminiStreamDecoder(),
            OllamaNdjsonDecoder::class => new OllamaNdjsonDecoder(),
            default => throw new \RuntimeException('unknown decoder'),
        };
    }
}

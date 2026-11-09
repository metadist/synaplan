<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\AI\Stream\StreamDecoderInterface;

/**
 * Reads one provider body outside the web request and writes the run stream.
 * The job carries a decoder name and the call payload, never a provider key.
 */
final class StreamPump
{
    /**
     * @param array<string, mixed> $call payload without a key or an authorization header
     */
    public function run(string $runId, string $bytes, StreamDecoderInterface $decoder, RunStream $stream, array $call = []): void
    {
        $this->assertNoSecret($call);
        $seq = 0;
        foreach ($decoder->feed($bytes) as $event) {
            $text = self::visible($event->kind, $event->data);
            if (null === $text) {
                continue;
            }
            $stream->append($runId, ++$seq, 'frame', 'data: '.json_encode(['text' => $text], \JSON_UNESCAPED_UNICODE)."\n\n");
        }
        $stream->append($runId, ++$seq, 'terminal', "data: [DONE]\n\n");
    }

    /**
     * @param array<string, mixed> $call
     */
    private function assertNoSecret(array $call): void
    {
        $encoded = json_encode($call) ?: '';
        foreach (['api_key', 'authorization', 'sk-'] as $needle) {
            if (str_contains(strtolower($encoded), $needle)) {
                throw new \InvalidArgumentException('A stream job must not carry a provider key');
            }
        }
    }

    private static function visible(string $kind, mixed $data): ?string
    {
        if ('content' === $kind && is_string($data) && '' !== $data) {
            return $data;
        }
        if ('callback' === $kind && is_array($data) && 'content' === ($data['type'] ?? null) && is_string($data['content'] ?? null) && '' !== $data['content']) {
            return $data['content'];
        }
        if ('event' === $kind && is_array($data)) {
            $text = $data['message']['content'] ?? $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (is_string($text) && '' !== $text) {
                return $text;
            }
        }

        return null;
    }
}

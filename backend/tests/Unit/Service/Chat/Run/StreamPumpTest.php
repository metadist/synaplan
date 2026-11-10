<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Run;

use App\AI\Stream\GeminiStreamDecoder;
use App\AI\Stream\OllamaNdjsonDecoder;
use App\AI\Stream\OpenAiChatCompletionsDecoder;
use App\Service\Chat\Run\MemoryRunStream;
use App\Service\Chat\Run\StreamPump;
use App\Service\Chat\Run\StreamPumpConfig;
use App\Service\Chat\Run\StreamPumpMode;
use PHPUnit\Framework\TestCase;

final class StreamPumpTest extends TestCase
{
    private const BODY = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\ndata: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n";

    protected function tearDown(): void
    {
        StreamPumpConfig::setOverride(null, null);
    }

    public function testThePumpWritesTheSameTextTheDecoderSaw(): void
    {
        $direct = new OpenAiChatCompletionsDecoder();
        $texts = [];
        foreach ($direct->feed(self::BODY) as $event) {
            if ('content' === $event->kind && is_string($event->data)) {
                $texts[] = $event->data;
            }
        }
        $stream = new MemoryRunStream();
        (new StreamPump())->run('run-1', self::BODY, new OpenAiChatCompletionsDecoder(), $stream, [
            'decoder' => 'openai_chat',
            'provider' => 'openai',
        ]);
        $frames = $stream->after('run-1', 0);
        $fromFrames = [];
        foreach ($frames as $frame) {
            if ('frame' !== $frame->kind) {
                continue;
            }
            $json = json_decode(substr(trim($frame->frame), 6), true);
            $fromFrames[] = $json['text'];
        }

        self::assertSame($texts, $fromFrames);
        self::assertSame('terminal', $frames[array_key_last($frames)]->kind);
        self::assertSame([], $stream->after('run-1', $frames[array_key_last($frames)]->seq));
    }

    public function testAJobRejectsAProviderKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StreamPump())->run('run-1', self::BODY, new OpenAiChatCompletionsDecoder(), new MemoryRunStream(), [
            'api_key' => 'sk-secret',
        ]);
    }

    public function testASentenceAboutAuthorizationIsStillAJob(): void
    {
        $stream = new MemoryRunStream();
        (new StreamPump())->run('run-1', self::BODY, new OpenAiChatCompletionsDecoder(), $stream, [
            'messages' => [['role' => 'user', 'content' => 'Explain authorization in Symfony.']],
        ]);

        $frames = $stream->after('run-1', 0);
        self::assertSame('terminal', $frames[array_key_last($frames)]->kind);
    }

    public function testAHeaderKeyIsRejectedEvenWithoutAnSkPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StreamPump())->run('run-1', self::BODY, new OpenAiChatCompletionsDecoder(), new MemoryRunStream(), [
            'headers' => ['X-Goog-Api-Key' => 'AIza-not-sk'],
        ]);
    }

    public function testMalformedTextDoesNotHideAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StreamPump())->run('run-1', self::BODY, new OpenAiChatCompletionsDecoder(), new MemoryRunStream(), [
            'api_key' => 'secret',
            'messages' => [['content' => "bad \xB1"]],
        ]);
    }

    public function testAProviderErrorIsAFailureFrameNotAnEmptyAnswer(): void
    {
        $stream = new MemoryRunStream();
        $body = "data: {\"error\":{\"message\":\"overloaded\"}}\n\n";

        try {
            (new StreamPump())->run('run-1', $body, new OpenAiChatCompletionsDecoder(), $stream);
            self::fail('A provider error must not look like a finished empty answer');
        } catch (\RuntimeException $e) {
            self::assertSame('overloaded', $e->getMessage());
        }

        $frames = $stream->after('run-1', 0);
        self::assertSame('frame', $frames[0]->kind);
        self::assertSame('data: {"error":"overloaded"}'."\n\n", $frames[0]->frame);
        self::assertSame('terminal', $frames[1]->kind);
    }

    public function testMistralPartsKeepTheAnswerAndDropTheThinking(): void
    {
        $body = 'data: {"choices":[{"delta":{"content":[{"type":"thinking","thinking":"secret"},{"type":"text","text":"Hi"}]}}]}'."\n\n";
        $stream = new MemoryRunStream();
        (new StreamPump())->run('run-1', $body, new OpenAiChatCompletionsDecoder(), $stream);

        self::assertSame(['Hi'], $this->texts($stream));
    }

    public function testGeminiThoughtsAreNotTheAnswer(): void
    {
        $body = 'data: {"candidates":[{"content":{"parts":[{"text":"secret","thought":true},{"text":"Hi"},{"text":" there"}]}}]}'."\n";
        $stream = new MemoryRunStream();
        (new StreamPump())->run('run-1', $body, new GeminiStreamDecoder(), $stream);

        self::assertSame(['Hi there'], $this->texts($stream));
    }

    public function testOllamaMessageTextIsTheAnswer(): void
    {
        $body = "{\"message\":{\"content\":\"Hi\"},\"done\":false}\n";
        $stream = new MemoryRunStream();
        (new StreamPump())->run('run-1', $body, new OllamaNdjsonDecoder(), $stream);

        self::assertSame(['Hi'], $this->texts($stream));
    }

    /**
     * @return list<string>
     */
    private function texts(MemoryRunStream $stream): array
    {
        $texts = [];
        foreach ($stream->after('run-1', 0) as $frame) {
            if ('frame' !== $frame->kind) {
                continue;
            }
            $json = json_decode(substr(trim($frame->frame), 6), true);
            $texts[] = $json['text'];
        }

        return $texts;
    }

    public function testTheSwitchIsOffUntilAnOperatorTurnsItOn(): void
    {
        $config = new StreamPumpConfig();
        self::assertFalse($config->handles('openai_api', 'run-1'));
        StreamPumpConfig::setOverride(StreamPumpMode::All, 0);
        self::assertFalse($config->handles('messages_api', 'run-1'));
        StreamPumpConfig::setOverride(StreamPumpMode::Gateways, 100);
        self::assertTrue($config->handles('openai_api', 'run-1'));
        self::assertFalse($config->handles('chat_stream', 'run-1'));
        StreamPumpConfig::setOverride(StreamPumpMode::All, 100);
        self::assertTrue($config->handles('chat_stream', 'run-1'));
    }
}

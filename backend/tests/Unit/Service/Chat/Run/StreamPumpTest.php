<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Run;

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

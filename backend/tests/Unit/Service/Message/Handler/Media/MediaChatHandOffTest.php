<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\Media\MediaChatHandOff;
use App\Service\Message\Handler\MessageHandlerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaChatHandOffTest extends TestCase
{
    public function testTheChatAnswersWithoutTheMediaClassification(): void
    {
        $handedOver = [];
        $chat = $this->createStub(ChatHandler::class);
        $chat->method('handleStream')->willReturnCallback(function (Message $message, array $thread, array $classification, callable $stream, ?callable $progress = null, array $options = []) use (&$handedOver): array {
            $handedOver = [$classification, $options];

            return ['metadata' => ['provider' => 'groq']];
        });

        $answer = (new MediaChatHandOff(new NullLogger(), $chat))->answerAsChatInstead(
            $this->createStub(Message::class),
            [],
            ['topic' => 'mediamaker', 'intent' => 'audio_generation', 'language' => 'en', 'media_type' => 'audio', 'input_mode' => 'text_only', 'duration' => 8, 'resolution' => '720p', 'model_id' => 45, 'prompt_metadata' => ['aiModel' => 45]],
            static function (): void {},
            null,
            ['resolved_prompt_data' => ['topic' => 'mediamaker'], 'channel' => 'WEB'],
            'Teach me numbers',
        );

        self::assertSame([['topic' => 'general', 'intent' => 'chat', 'language' => 'en', 'rerouted_from' => 'mediamaker:audio'], ['channel' => 'WEB']], $handedOver);
        self::assertSame('mediamaker:audio', $answer['metadata']['rerouted_from']);
        self::assertSame(['topic' => 'general', 'intent' => 'chat', 'media_type' => null, 'rerouted_from' => 'mediamaker:audio'], $answer['metadata'][MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY]);
    }

    public function testWithoutAChatHandlerTheUserIsAskedToRephrase(): void
    {
        $streamed = [];
        $answer = (new MediaChatHandOff(new NullLogger()))->answerAsChatInstead(
            $this->createStub(Message::class),
            [],
            ['language' => 'de'],
            static function (string $text) use (&$streamed): void {
                $streamed[] = $text;
            },
            null,
            [],
            'Teach me numbers',
        );

        self::assertSame(['metadata' => ['error' => 'tts_script_echoes_request']], $answer);
        self::assertStringStartsWith('Ich habe in deiner Nachricht keinen Text gefunden', $streamed[0]);
    }
}

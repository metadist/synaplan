<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\MediaPromptExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MediaPromptExtractorTest extends TestCase
{
    private Message $message;
    private array $classification = ['topic' => 'mediamaker', 'language' => 'de'];

    protected function setUp(): void
    {
        $this->message = (new Message())
            ->setUserId(42)
            ->setTrackingId(1001)
            ->setText('erstelle eine audio wo du hallo sagst');
    }

    public function testExtractsPromptFromJsonResponse(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $chatHandler->expects($this->once())
            ->method('handle')
            ->with(
                $this->identicalTo($this->message),
                $this->equalTo([]),
                $this->isType('array'),
                $this->isNull()
            )
            ->willReturn(['content' => '{"BMEDIA":"audio","BTEXT":"Hallo"}']);

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame('Hallo', $result['prompt']);
        $this->assertSame('audio', $result['media_type']);
    }

    public function testForcesMediamakerTopicAndRemovesModelOverrides(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $chatHandler->expects($this->once())
            ->method('handle')
            ->with(
                $this->identicalTo($this->message),
                $this->equalTo([]),
                $this->callback(function (array $classification): bool {
                    $this->assertSame('mediamaker', $classification['topic']);
                    $this->assertSame('image_generation', $classification['intent']);
                    $this->assertSame('de', $classification['language']);
                    $this->assertArrayNotHasKey('model_id', $classification);
                    $this->assertArrayNotHasKey('override_model_id', $classification);

                    return true;
                }),
                $this->isNull()
            )
            ->willReturn(['content' => '{"BMEDIA":"audio","BTEXT":"Hallo"}']);

        $classification = [
            'topic' => 'general',
            'language' => 'de',
            'model_id' => 123,
            'override_model_id' => 999,
        ];

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $extractor->extract($this->message, [], $classification);
    }

    public function testReturnsRawTextWhenJsonMissingForNonAudio(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->message->setText('Bitte male ein Bild von einem See');
        $responseText = 'Please create an image of a lake';

        $chatHandler->expects($this->once())
            ->method('handle')
            ->willReturn(['content' => $responseText]);

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame($responseText, $result['prompt']);
        $this->assertNull($result['media_type']);
    }

    public function testFallsBackToMessageTextOnException(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->message->setText('Bitte hilf mir bei einer Frage');

        $chatHandler->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('boom'));

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame($this->message->getText(), $result['prompt']);
        $this->assertNull($result['media_type']);
    }

    public function testUsesMediaTypeFromClassificationWhenPresent(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        // AI returns JSON with media_type "image", but classification has "video"
        $chatHandler->expects($this->once())
            ->method('handle')
            ->willReturn(['content' => '{"BMEDIA":"image","BTEXT":"Enhanced prompt"}']);

        // Expect logging when using sorter-provided media_type (among other info logs)
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function (string $message, array $context = []) {
                // Verify the specific log message is called
                if ('MediaPromptExtractor: Using media_type from sorter' === $message) {
                    $this->assertSame('video', $context['media_type']);
                }
            });

        $classification = [
            'topic' => 'mediamaker',
            'language' => 'en',
            'media_type' => 'video', // This should take precedence over JSON
        ];

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $classification);

        $this->assertSame('Enhanced prompt', $result['prompt']);
        $this->assertSame('video', $result['media_type']); // Classification wins
    }

    public function testFallsBackToJsonMediaTypeWhenClassificationDoesNotProvideIt(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        // AI returns JSON with media_type
        $chatHandler->expects($this->once())
            ->method('handle')
            ->willReturn(['content' => '{"BMEDIA":"audio","BTEXT":"Hallo Welt"}']);

        // Verify the sorter log is NOT called (since classification doesn't provide media_type)
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function (string $message) {
                // This specific log should NOT be called
                $this->assertNotSame(
                    'MediaPromptExtractor: Using media_type from sorter',
                    $message,
                    'Should not use sorter media_type when classification does not provide it'
                );
            });

        $classification = [
            'topic' => 'mediamaker',
            'language' => 'de',
            // No media_type in classification - should fall back to JSON
        ];

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $classification);

        $this->assertSame('Hallo Welt', $result['prompt']);
        $this->assertSame('audio', $result['media_type']); // Falls back to JSON
    }

    public function testPreservesMediaTypeFromClassificationOnException(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $chatHandler->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('AI service unavailable'));

        $classification = [
            'topic' => 'mediamaker',
            'language' => 'en',
            'media_type' => 'video', // Should be preserved in fallback
        ];

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $classification);

        $this->assertSame($this->message->getText(), $result['prompt']);
        $this->assertSame('video', $result['media_type']); // Preserved from classification
    }

    public function testTriggersAudioFallbackWhenResponseIsNotJson(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $call = 0;
        $chatHandler->expects($this->exactly(2))
            ->method('handle')
            ->willReturnCallback(function ($message, $thread, $classification, $progress) use (&$call) {
                if (0 === $call) {
                    $this->assertSame('mediamaker', $classification['topic']);
                    ++$call;

                    return ['content' => 'Audio Prompt: Erstelle eine Audiodatei, in der ich sage: "Hallo, was geht?"'];
                }

                $this->assertSame('tools:mediamaker_audio_extract', $classification['topic']);

                return ['content' => 'Hallo, was geht?'];
            });

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame('Hallo, was geht?', $result['prompt']);
        $this->assertSame('audio', $result['media_type']);
    }

    public function testInfersAudioIntentWhenJsonDoesNotContainMediaType(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->message->setText('Sende mir eine Audiodatei und sag Hallo, wie gehts dir?');

        $chatHandler->expects($this->exactly(2))
            ->method('handle')
            ->willReturnCallback(function ($message, $thread, $classification, $progress) {
                if ('mediamaker' === $classification['topic']) {
                    // JSON without BMEDIA should trigger inference + audio fallback extraction
                    return ['content' => '{"BTEXT":"some generic media prompt"}'];
                }

                $this->assertSame('tools:mediamaker_audio_extract', $classification['topic']);

                return ['content' => 'Hallo, wie gehts dir?'];
            });

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame('Hallo, wie gehts dir?', $result['prompt']);
        $this->assertSame('audio', $result['media_type']);
    }
}

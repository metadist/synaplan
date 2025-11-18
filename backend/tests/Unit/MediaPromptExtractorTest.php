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

        $chatHandler->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('boom'));

        $extractor = new MediaPromptExtractor($chatHandler, $logger);
        $result = $extractor->extract($this->message, [], $this->classification);

        $this->assertSame($this->message->getText(), $result['prompt']);
        $this->assertNull($result['media_type']);
    }

    public function testTriggersAudioFallbackWhenResponseIsNotJson(): void
    {
        $chatHandler = $this->createMock(ChatHandler::class);
        $logger = $this->createMock(LoggerInterface::class);

        $call = 0;
        $chatHandler->expects($this->exactly(2))
            ->method('handle')
            ->willReturnCallback(function ($message, $thread, $classification, $progress) use (&$call) {
                if ($call === 0) {
                    $this->assertSame('mediamaker', $classification['topic']);
                    $call++;
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
}


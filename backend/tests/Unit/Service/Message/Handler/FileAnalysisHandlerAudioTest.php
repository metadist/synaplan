<?php

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\ModelConfigService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #955 — when the user sends a voice message, the assistant
 * must respond conversationally to what they said. Before this fix
 * the handler reused the generic "You are analyzing a document"
 * prompt for transcribed audio, which produced meta-commentary like:
 *
 *   "The OGG audio file contains a very short recording that says:
 *    'Hei, test.' That's all the spoken content in the file."
 *
 * These tests pin the new conversational prompt path so the
 * regression cannot reappear silently.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class FileAnalysisHandlerAudioTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private LoggerInterface&MockObject $logger;
    private FileAnalysisHandler $handler;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new FileAnalysisHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->logger,
            '/var/www/backend/var/uploads',
        );
    }

    public function testAudioMessageUsesConversationalPromptInsteadOfDocumentAnalysis(): void
    {
        // An audio-only upload from the web app reaches the handler with
        // an empty `BTEXT` (the i18n placeholder shown in the user bubble
        // is not forwarded as message content) — so the structural empty
        // check inside `isGenericAudioPlaceholder` is what triggers the
        // conversational path here.
        $message = $this->buildAudioMessage(
            text: '',
            transcript: 'Hei, test.',
        );

        $this->stubModelConfig();

        $capturedMessages = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return [
                    'content' => 'Hi! What would you like to test?',
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                ];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($capturedMessages);
        $this->assertCount(2, $capturedMessages);

        $system = $capturedMessages[0]['content'];
        $this->assertStringContainsString('voice message', $system);
        $this->assertStringContainsString('Hei, test.', $system);
        $this->assertStringNotContainsString('You are analyzing a document', $system);
        $this->assertStringContainsString('do NOT', $system);
        $this->assertStringContainsString('OGG', $system);

        // Generic file-only placeholder must be replaced by the transcript so
        // the LLM has something to actually reply to.
        $this->assertSame('Hei, test.', $capturedMessages[1]['content']);

        $this->assertSame('Hi! What would you like to test?', $result['content']);
        $this->assertSame('voice_message_reply', $result['metadata']['analysis_type']);
    }

    /**
     * Mobile platforms (WhatsApp, iOS, Android) send a bracketed marker
     * like `[Audio message]` or `[voice]` when the row has no real text.
     * `MessagePreProcessor` already swaps `[Audio message]` for the
     * transcript on the WhatsApp path, but the handler must still treat
     * any bracketed-only placeholder as generic on its own — that
     * double-defense is what removed the brittle i18n string list.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideBracketedAudioPlaceholders(): iterable
    {
        yield '[audio message] (lowercase)' => ['[audio message]'];
        yield '[Audio] (titlecase)' => ['[Audio]'];
        yield '[Voice note] (titlecase, two words)' => ['[Voice note]'];
        yield 'whitespace-padded' => ['   [voice]   '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBracketedAudioPlaceholders')]
    public function testBracketedMarkerIsTreatedAsGenericPlaceholder(string $marker): void
    {
        $message = $this->buildAudioMessage(
            text: $marker,
            transcript: 'Hei, test.',
        );

        $this->stubModelConfig();

        $capturedMessages = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return ['content' => 'Hi!', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $this->handler->handle($message, [], []);

        $this->assertNotNull($capturedMessages);
        $this->assertSame('Hei, test.', $capturedMessages[1]['content']);
    }

    public function testAudioMessagePreservesUserPromptWhenNotGeneric(): void
    {
        $message = $this->buildAudioMessage(
            text: 'Übersetze das auf Englisch.',
            transcript: 'Hallo, wie geht es dir?',
        );

        $this->stubModelConfig();

        $capturedMessages = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return [
                    'content' => 'Hello, how are you?',
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                ];
            });

        $this->handler->handle($message, [], []);

        $this->assertNotNull($capturedMessages);
        $this->assertSame('Übersetze das auf Englisch.', $capturedMessages[1]['content']);
        $this->assertStringContainsString('Hallo, wie geht es dir?', $capturedMessages[0]['content']);
    }

    public function testStreamingAudioMessageUsesConversationalPrompt(): void
    {
        $message = $this->buildAudioMessage(
            text: '',
            transcript: 'Hei, test.',
        );

        $this->stubModelConfig();

        $capturedMessages = null;
        $streamedContent = '';
        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(
                function (array $messages, callable $callback) use (&$capturedMessages) {
                    $capturedMessages = $messages;
                    $callback('Hi! What can I help you with?');

                    return ['provider' => 'openai', 'model' => 'gpt-4'];
                }
            );

        $streamCallback = function (string $chunk) use (&$streamedContent): void {
            $streamedContent .= $chunk;
        };

        $result = $this->handler->handleStream($message, [], [], $streamCallback);

        $this->assertNotNull($capturedMessages);
        $this->assertStringContainsString('voice message', $capturedMessages[0]['content']);
        $this->assertStringContainsString('Hei, test.', $capturedMessages[0]['content']);
        $this->assertSame('Hei, test.', $capturedMessages[1]['content']);
        $this->assertSame('Hi! What can I help you with?', $streamedContent);
        $this->assertSame('voice_message_reply', $result['metadata']['analysis_type']);
    }

    public function testAudioPromptTreatsTranscriptCopyAsGeneric(): void
    {
        // The mobile app sometimes pre-fills the message text with the
        // STT transcript itself. In that case the LLM should still get
        // the transcript as the user turn (just once) instead of being
        // told "your job is to summarise this".
        $message = $this->buildAudioMessage(
            text: 'Hei, test.',
            transcript: 'Hei, test.',
        );

        $this->stubModelConfig();

        $capturedMessages = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return ['content' => 'Hello!', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $this->handler->handle($message, [], []);

        $this->assertNotNull($capturedMessages);
        $this->assertSame('Hei, test.', $capturedMessages[1]['content']);
    }

    public function testAudioWithoutTranscriptionReturnsError(): void
    {
        $message = $this->buildAudioMessage(
            text: 'Bitte prüfe die angehängte Datei.',
            transcript: '',
        );

        // No chat call must happen when the transcript is missing.
        $this->aiFacade->expects($this->never())->method('chat');
        $this->aiFacade->expects($this->never())->method('chatStream');

        $result = $this->handler->handle($message, [], []);

        $this->assertStringContainsString('Audio transcription failed', $result['content']);
        $this->assertSame('audio_not_transcribed', $result['metadata']['error']);
    }

    /**
     * Build a Message mock that exposes a single transcribed audio File entity.
     */
    private function buildAudioMessage(string $text, string $transcript): Message&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(99);
        $file->method('getFileName')->willReturn('voice.ogg');
        $file->method('getFileType')->willReturn('ogg');
        $file->method('getFilePath')->willReturn('13/000/voice.ogg');
        $file->method('getFileText')->willReturn($transcript);
        $file->method('getStatus')->willReturn('processed');

        $files = new ArrayCollection([$file]);

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getUserId')->willReturn(7);
        $message->method('getFiles')->willReturn($files);
        $message->method('getText')->willReturn($text);

        return $message;
    }

    private function stubModelConfig(): void
    {
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(123);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4');
    }
}

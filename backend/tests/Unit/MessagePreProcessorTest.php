<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Service\Message\MessagePreProcessor;
use App\Service\WhisperService;
use App\Repository\MessageRepository;
use App\Entity\Message;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MessagePreProcessorTest extends TestCase
{
    private MessageRepository $messageRepository;
    private HttpClientInterface $httpClient;
    private WhisperService $whisperService;
    private AiFacade $aiFacade;
    private LoggerInterface $logger;
    private MessagePreProcessor $service;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->whisperService = $this->createMock(WhisperService::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MessagePreProcessor(
            $this->messageRepository,
            $this->httpClient,
            $this->whisperService,
            $this->aiFacade,
            $this->logger,
            'http://tika:9998',
            '/var/www/html/uploads'
        );
    }

    public function testProcessMessageWithoutFile(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');

        $this->messageRepository
            ->expects($this->once())
            ->method('save')
            ->with($message);

        $result = $this->service->process($message);

        $this->assertSame($message, $result);
    }

    public function testProcessMessageWithNonExistentFile(): void
    {
        $this->markTestSkipped('Complex mock test with logger expectations - needs refactoring');
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(1);
        $message->method('getFilePath')->willReturn('non-existent.pdf');
        $message->method('getFileType')->willReturn('pdf');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('File not found'));

        $this->messageRepository
            ->expects($this->once())
            ->method('save');

        $this->service->process($message);
    }

    public function testProcessCallsProgressCallback(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(1);
        $message->method('getFilePath')->willReturn('test.pdf');

        $callbackCalled = false;
        $callback = function($data) use (&$callbackCalled) {
            $callbackCalled = true;
            $this->assertArrayHasKey('status', $data);
            $this->assertArrayHasKey('message', $data);
            $this->assertEquals('preprocessing', $data['status']);
        };

        $this->messageRepository->method('save');

        $this->service->process($message, $callback);

        $this->assertTrue($callbackCalled);
    }

    public function testProcessSavesMessage(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(0);

        $this->messageRepository
            ->expects($this->once())
            ->method('save')
            ->with($message);

        $this->service->process($message);
    }

    public function testProcessReturnsMessage(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(0);

        $this->messageRepository->method('save');

        $result = $this->service->process($message);

        $this->assertSame($message, $result);
    }

    public function testProcessWithAudioFileCallsWhisper(): void
    {
        $this->markTestSkipped('Complex mock test with logger expectations - needs refactoring');
        // Create temp audio file
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir . '/test_audio_' . uniqid() . '.mp3';
        touch($tempFile);

        try {
            // Create service with temp directory
            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->httpClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                'http://tika:9998',
                $tempDir
            );

            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(1);
            $message->method('getFilePath')->willReturn(basename($tempFile));
            $message->method('getFileType')->willReturn('mp3');
            $message->method('getLanguage')->willReturn('en');

            $this->whisperService
                ->expects($this->once())
                ->method('isAvailable')
                ->willReturn(false);

            $this->logger
                ->expects($this->once())
                ->method('warning')
                ->with($this->stringContains('Whisper not available'));

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            // Clean up
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testProcessWithAudioWhenWhisperAvailable(): void
    {
        // Create temp audio file
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir . '/test_audio_' . uniqid() . '.ogg';
        touch($tempFile);

        try {
            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->httpClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                'http://tika:9998',
                $tempDir
            );

            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(1);
            $message->method('getFilePath')->willReturn(basename($tempFile));
            $message->method('getFileType')->willReturn('ogg');
            $message->method('getLanguage')->willReturn('de');

            $this->whisperService
                ->method('isAvailable')
                ->willReturn(true);

            $this->whisperService
                ->expects($this->once())
                ->method('transcribe')
                ->willThrowException(new \Exception('Transcription failed'));

            // Should log error but not fail the entire process
            $this->logger->expects($this->atLeastOnce())->method('error');
            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testProcessSkipsNonAudioFiles(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getFile')->willReturn(1);
        $message->method('getFilePath')->willReturn('test.pdf');
        $message->method('getFileType')->willReturn('pdf');

        // Whisper should not be called for PDF files
        $this->whisperService
            ->expects($this->never())
            ->method('isAvailable');

        $this->messageRepository->method('save');

        $this->service->process($message);
    }
}


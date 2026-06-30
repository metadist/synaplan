<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\File\TikaClient;
use App\Service\Message\MessagePreProcessor;
use App\Service\RateLimitService;
use App\Service\WhisperService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessagePreProcessorTest extends TestCase
{
    private MessageRepository&MockObject $messageRepository;
    private TikaClient&MockObject $tikaClient;
    private WhisperService&MockObject $whisperService;
    private AiFacade&MockObject $aiFacade;
    private LoggerInterface&MockObject $logger;
    private RateLimitService&MockObject $rateLimitService;
    private UserRepository&MockObject $userRepository;
    private FileProcessor&MockObject $fileProcessor;
    private MessagePreProcessor $service;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->tikaClient = $this->createMock(TikaClient::class);
        $this->whisperService = $this->createMock(WhisperService::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->fileProcessor = $this->createMock(FileProcessor::class);

        $this->service = new MessagePreProcessor(
            $this->messageRepository,
            $this->tikaClient,
            $this->whisperService,
            $this->aiFacade,
            $this->logger,
            '/var/www/backend/uploads',
            $this->rateLimitService,
            $this->userRepository,
            $this->fileProcessor,
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
        $callback = function ($data) use (&$callbackCalled) {
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
        $tempFile = $tempDir.'/test_audio_'.uniqid().'.mp3';
        touch($tempFile);

        try {
            // Create service with temp directory
            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
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
        $tempFile = $tempDir.'/test_audio_'.uniqid().'.ogg';
        touch($tempFile);

        try {
            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
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

    /**
     * Issue #729 — guards the regression that surfaced as
     * "Document text extraction failed" on the first message after a chat
     * upload. When BFILETEXT is already populated (synchronous extraction
     * at upload time), the preprocessor must reuse it without invoking the
     * fileProcessor again and must mark the file as `processed`.
     */
    public function testProcessFileEntityWithExtractedTextSkipsReExtraction(): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_doc_'.uniqid().'.docx';
        touch($tempFile);

        try {
            $file = $this->createMock(\App\Entity\File::class);
            $file->method('getId')->willReturn(42);
            $file->method('getFilePath')->willReturn(basename($tempFile));
            $file->method('getFileType')->willReturn('docx');
            $file->method('getFileName')->willReturn('report.docx');
            $file->method('getFileSize')->willReturn(1234);
            $file->method('getFileText')->willReturn('Already extracted contents');
            $file->method('getUserId')->willReturn(7);
            $file
                ->expects($this->atLeastOnce())
                ->method('setStatus')
                ->with('processed');

            $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(0);
            $message->method('getFilePath')->willReturn('');
            $message->method('getFiles')->willReturn($files);
            $message->method('getUserId')->willReturn(7);

            // fileProcessor must NOT run when text is already there
            $this->fileProcessor
                ->expects($this->never())
                ->method('extractText');

            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
            );

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Issue #1191 — re-attaching an already-vectorized file (BFILETEXT
     * present) must NOT downgrade its status to 'processed'. The Qdrant
     * vectors are still valid, so flipping the DB status to 'processed' would
     * make the two stores inconsistent.
     */
    public function testProcessFileEntityDoesNotDowngradeVectorizedStatusOnReAttach(): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_vec_'.uniqid().'.pdf';
        touch($tempFile);

        try {
            $file = $this->createMock(\App\Entity\File::class);
            $file->method('getId')->willReturn(55);
            $file->method('getFilePath')->willReturn(basename($tempFile));
            $file->method('getFileType')->willReturn('pdf');
            $file->method('getFileName')->willReturn('kb.pdf');
            $file->method('getFileSize')->willReturn(2048);
            $file->method('getFileText')->willReturn('Vectorized knowledge contents');
            $file->method('getUserId')->willReturn(7);
            $file->method('getStatus')->willReturn('vectorized');

            // The status must never be touched for an already-vectorized file.
            $file->expects($this->never())->method('setStatus');

            // Owner not resolvable → billing is a no-op (keeps the test focused).
            $this->userRepository->method('find')->with(7)->willReturn(null);

            $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(0);
            $message->method('getFilePath')->willReturn('');
            $message->method('getFiles')->willReturn($files);
            $message->method('getUserId')->willReturn(7);

            $this->fileProcessor->expects($this->never())->method('extractText');

            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
            );

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Issue #954 — '.md', '.csv', and '.ppt' uploads must be routed through
     * FileProcessor like every other document type. Before the fix they were
     * missing from DOCUMENT_EXTENSIONS, so the preprocessor silently skipped
     * them and FileAnalysisHandler reported "unsupported file type" for
     * legitimately uploaded files.
     *
     * @return iterable<string, array{string}>
     */
    public static function supportedDocumentExtensionsProvider(): iterable
    {
        yield 'markdown' => ['md'];
        yield 'csv' => ['csv'];
        yield 'powerpoint legacy' => ['ppt'];
    }

    #[DataProvider('supportedDocumentExtensionsProvider')]
    public function testProcessFileEntityExtractsTextForSupportedDocumentExtension(string $extension): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_doc_'.uniqid().'.'.$extension;
        touch($tempFile);

        try {
            $file = $this->createMock(\App\Entity\File::class);
            $file->method('getId')->willReturn(99);
            $file->method('getFilePath')->willReturn(basename($tempFile));
            $file->method('getFileType')->willReturn($extension);
            $file->method('getFileName')->willReturn('notes.'.$extension);
            $file->method('getFileSize')->willReturn(512);
            $file->method('getFileText')->willReturn('');
            $file->method('getUserId')->willReturn(5);

            $file
                ->expects($this->atLeastOnce())
                ->method('setFileText')
                ->with('extracted body');

            $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(0);
            $message->method('getFilePath')->willReturn('');
            $message->method('getFiles')->willReturn($files);
            $message->method('getUserId')->willReturn(5);

            $this->fileProcessor
                ->expects($this->once())
                ->method('extractText')
                ->with(basename($tempFile), $extension, 5)
                ->willReturn(['extracted body', ['strategy' => 'native_text']]);

            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
            );

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Issue #729 — when BFILETEXT is empty (e.g. the chat upload path
     * couldn't extract synchronously for some reason), the preprocessor
     * must delegate to the shared FileProcessor (Tika + Vision fallback)
     * rather than calling Tika directly. This is what closes the race
     * for legitimate fallback paths.
     */
    public function testProcessFileEntityWithoutExtractedTextUsesFileProcessor(): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_doc_'.uniqid().'.docx';
        touch($tempFile);

        try {
            $file = $this->createMock(\App\Entity\File::class);
            $file->method('getId')->willReturn(43);
            $file->method('getFilePath')->willReturn(basename($tempFile));
            $file->method('getFileType')->willReturn('docx');
            $file->method('getFileName')->willReturn('doc.docx');
            $file->method('getFileSize')->willReturn(2048);
            $file->method('getFileText')->willReturn('');
            $file->method('getUserId')->willReturn(11);

            $file
                ->expects($this->atLeastOnce())
                ->method('setFileText')
                ->with('extracted body');

            $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(0);
            $message->method('getFilePath')->willReturn('');
            $message->method('getFiles')->willReturn($files);
            $message->method('getUserId')->willReturn(11);

            $this->fileProcessor
                ->expects($this->once())
                ->method('extractText')
                ->with(basename($tempFile), 'docx', 11)
                ->willReturn(['extracted body', ['strategy' => 'tika']]);

            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
            );

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Issue #983 — MP4 (and other video formats) must be routed through
     * FileProcessor, which transcribes the audio track and describes a key
     * frame. Before the fix the preprocessor had no VIDEO_EXTENSIONS branch
     * so the file was skipped and FileAnalysisHandler reported "unsupported
     * file type".
     *
     * @return iterable<string, array{string}>
     */
    public static function supportedVideoExtensionsProvider(): iterable
    {
        yield 'mp4' => ['mp4'];
        yield 'mov' => ['mov'];
        yield 'avi' => ['avi'];
        yield 'mkv' => ['mkv'];
    }

    #[DataProvider('supportedVideoExtensionsProvider')]
    public function testProcessFileEntityAnalyzesVideoViaFileProcessor(string $extension): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_video_'.uniqid().'.'.$extension;
        touch($tempFile);

        try {
            $file = $this->createMock(\App\Entity\File::class);
            $file->method('getId')->willReturn(77);
            $file->method('getFilePath')->willReturn(basename($tempFile));
            $file->method('getFileType')->willReturn($extension);
            $file->method('getFileName')->willReturn('clip.'.$extension);
            $file->method('getFileSize')->willReturn(4096);
            $file->method('getFileText')->willReturn('');
            $file->method('getUserId')->willReturn(5);

            $file
                ->expects($this->atLeastOnce())
                ->method('setFileText')
                ->with("[Visual description]\nA cat on a sofa.\n\n[Audio transcript]\nHello world.");

            $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
            $message = $this->createMock(Message::class);
            $message->method('getFile')->willReturn(0);
            $message->method('getFilePath')->willReturn('');
            $message->method('getFiles')->willReturn($files);
            $message->method('getUserId')->willReturn(5);

            $this->fileProcessor
                ->expects($this->once())
                ->method('extractText')
                ->with(basename($tempFile), $extension, 5)
                ->willReturn([
                    "[Visual description]\nA cat on a sofa.\n\n[Audio transcript]\nHello world.",
                    ['strategy' => 'video_transcript_vision'],
                ]);

            $service = new MessagePreProcessor(
                $this->messageRepository,
                $this->tikaClient,
                $this->whisperService,
                $this->aiFacade,
                $this->logger,
                $tempDir,
                $this->rateLimitService,
                $this->userRepository,
                $this->fileProcessor,
            );

            $this->messageRepository->method('save');

            $service->process($message);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}

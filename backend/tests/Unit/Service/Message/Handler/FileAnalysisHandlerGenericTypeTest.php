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
 * Issue #1236 — files produced by the generated-media pipelines store a
 * generic kind ("image", "audio", "video", "document") in BFILETYPE
 * instead of a concrete extension ("png", …). The previous routing only
 * accepted extensions, so re-attaching a generated PNG (or a DAG
 * `file_analysis` node consuming one) dead-ended in the "This file type
 * cannot be analyzed" branch even though the filename and MIME clearly
 * identified it — the error message even listed PNG as supported.
 *
 * These tests pin the normalization so a generic kind is routed to the
 * same handler path as its equivalent extension.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class FileAnalysisHandlerGenericTypeTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private LoggerInterface&MockObject $logger;
    private FileAnalysisHandler $handler;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->uploadDir = sys_get_temp_dir().'/synaplan-file-analysis-generic-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);

        $this->handler = new FileAnalysisHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->logger,
            $this->uploadDir,
        );

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(123);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            $this->removeDirectoryRecursive($this->uploadDir);
        }
    }

    /**
     * The core #1236 regression: a generated PNG stored with the generic
     * BFILETYPE "image" must be analyzed via the Vision path exactly like a
     * freshly uploaded ".png", instead of failing with "unsupported".
     */
    public function testGeneratedImageWithGenericImageTypeRoutesToVision(): void
    {
        $path = '13/000/media_1_google_cat.png';
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 39, name: 'media_1_google_cat.png', type: 'image', path: $path),
        ], text: 'beschreibe mir was ich hier sehen kann');

        $this->stubImagePathsExist([$path]);

        $this->aiFacade
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturn([
                'content' => 'A cat lounging on a sofa.',
                'provider' => 'openai',
                'model' => 'gpt-4o',
            ]);

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('vision', $result['metadata']['analysis_type']);
        $this->assertStringContainsString('A cat lounging on a sofa.', $result['content']);
    }

    /**
     * A generic "image" file with no extension anywhere (neither on the
     * filename nor the path) must still fall back to the Vision path rather
     * than dead-ending as unsupported.
     */
    public function testGenericImageWithoutExtensionFallsBackToVision(): void
    {
        $path = 'present/generated-artifact';
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 40, name: 'generated-artifact', type: 'image', path: $path),
        ], text: 'what is this?');

        $this->stubImagePathsExist([$path]);

        $this->aiFacade
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(['content' => 'An abstract render.', 'provider' => 'openai', 'model' => 'gpt-4o']);

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('vision', $result['metadata']['analysis_type']);
        $this->assertStringContainsString('An abstract render.', $result['content']);
    }

    /**
     * A file whose BFILETYPE is empty but whose filename carries a concrete
     * extension must be recovered from the filename (defensive: legacy rows
     * or partially-populated File entities).
     */
    public function testEmptyTypeWithPngFilenameRoutesToVision(): void
    {
        $path = 'present/screenshot.png';
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 41, name: 'screenshot.png', type: '', path: $path),
        ], text: '');

        $this->stubImagePathsExist([$path]);

        $this->aiFacade
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(['content' => 'A screenshot.', 'provider' => 'openai', 'model' => 'gpt-4o']);

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('vision', $result['metadata']['analysis_type']);
    }

    /**
     * A generated audio artefact stored as the generic kind "audio" with a
     * transcript must reach the conversational voice-reply path.
     */
    public function testGenericAudioTypeRoutesToVoiceReply(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 42, name: 'media_1_tts.mp3', type: 'audio', path: '13/000/media_1_tts.mp3', text: 'Hello, remind me tomorrow.'),
        ], text: '');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured): array {
                $captured = $messages;

                return ['content' => 'Sure!', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Hello, remind me tomorrow.', $captured[0]['content']);
        $this->assertSame('voice_message_reply', $result['metadata']['analysis_type']);
    }

    /**
     * A generated video artefact stored as the generic kind "video" with a
     * transcript must be summarized via the document/chat path.
     */
    public function testGenericVideoTypeRoutesToDocumentPath(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 43, name: 'media_1_clip.mp4', type: 'video', path: '13/000/media_1_clip.mp4', text: 'A short product reveal.'),
        ], text: 'summarize');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured): array {
                $captured = $messages;

                return ['content' => 'It reveals a product.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('A short product reveal.', $captured[0]['content']);
        $this->assertSame('chat_with_extracted_text', $result['metadata']['analysis_type']);
    }

    /**
     * A generated document stored as the generic kind "document" with
     * extracted text must reach the document analysis path.
     */
    public function testGenericDocumentTypeRoutesToDocumentPath(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 44, name: 'media_1_report.docx', type: 'document', path: '13/000/media_1_report.docx', text: 'Quarterly numbers are up.'),
        ], text: 'what does it say?');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured): array {
                $captured = $messages;

                return ['content' => 'Numbers up.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Quarterly numbers are up.', $captured[0]['content']);
        $this->assertSame('chat_with_extracted_text', $result['metadata']['analysis_type']);
    }

    /**
     * @param list<File> $files
     */
    private function buildMessageWithFiles(array $files, string $text): Message&MockObject
    {
        $collection = new ArrayCollection($files);

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getUserId')->willReturn(7);
        $message->method('getFiles')->willReturn($collection);
        $message->method('getText')->willReturn($text);

        return $message;
    }

    private function buildFile(
        int $id,
        string $name,
        string $type,
        string $path,
        string $text = '',
        string $status = 'processed',
    ): File&MockObject {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getFileName')->willReturn($name);
        $file->method('getFileType')->willReturn($type);
        $file->method('getFilePath')->willReturn($path);
        $file->method('getFileText')->willReturn($text);
        $file->method('getStatus')->willReturn($status);

        return $file;
    }

    /**
     * @param list<string> $relativePaths
     */
    private function stubImagePathsExist(array $relativePaths): void
    {
        foreach ($relativePaths as $relative) {
            $full = $this->uploadDir.'/'.$relative;
            $dir = dirname($full);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed to create test upload directory "%s"', $dir));
            }
            if (false === file_put_contents($full, 'fake-image-bytes')) {
                throw new \RuntimeException(sprintf('Failed to write test upload file "%s"', $full));
            }
        }
    }

    private function removeDirectoryRecursive(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

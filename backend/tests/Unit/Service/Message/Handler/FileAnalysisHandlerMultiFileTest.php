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
 * Issue #978 — when the user attaches multiple documents (or voice
 * messages) to a single chat message, the previous implementation
 * silently dropped every file after the first because
 * `FileAnalysisHandler` called `$files->first()` once. These tests
 * pin the multi-file behaviour so the regression cannot reappear.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class FileAnalysisHandlerMultiFileTest extends TestCase
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

        // Use a hermetic per-test upload directory so the vision-path
        // tests work in any environment (Docker container, GitHub
        // Actions runner, dev laptop) without depending on a hardcoded
        // `/var/www/backend/var/uploads` location.
        $this->uploadDir = sys_get_temp_dir().'/synaplan-file-analysis-'.bin2hex(random_bytes(8));
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
     * Primary regression for #978: two .md documents attached to one
     * message used to feed only the first file's text to the LLM,
     * which made the assistant ignore the second document entirely.
     * After the fix, both files' extracted text must reach the model
     * in one labelled prompt.
     */
    public function testTwoDocumentsBothReachTheModelInOnePrompt(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'rules.md', type: 'md', path: '13/000/rules.md', text: 'Rule 1: ship small PRs.'),
            $this->buildFile(id: 2, name: 'plan.md', type: 'md', path: '13/000/plan.md', text: 'Step A: write tests.'),
        ], text: 'Evaluate my plan based on the rules.');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return [
                    'content' => 'Your plan aligns with the rules.',
                    'provider' => 'openai',
                    'model' => 'gpt-4',
                ];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('analyzing 2 documents', $system);
        $this->assertStringContainsString('rules.md', $system);
        $this->assertStringContainsString('plan.md', $system);
        $this->assertStringContainsString('Rule 1: ship small PRs.', $system);
        $this->assertStringContainsString('Step A: write tests.', $system);
        $this->assertStringContainsString('=== FILE 1 OF 2 INFORMATION ===', $system);
        $this->assertStringContainsString('=== FILE 2 OF 2 INFORMATION ===', $system);

        $this->assertSame('Evaluate my plan based on the rules.', $captured[1]['content']);
        $this->assertSame('Your plan aligns with the rules.', $result['content']);
        $this->assertSame('chat_with_extracted_text', $result['metadata']['analysis_type']);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
        $this->assertSame('rules.md, plan.md', $result['metadata']['analyzed_file']);
    }

    /**
     * The single-document case must keep producing the original
     * prompt layout so existing UX and prompt-quality tuning are
     * preserved. This guards against accidentally regressing the
     * common case while fixing the multi-file one.
     */
    public function testSingleDocumentUsesLegacyPromptShape(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'report.pdf', type: 'pdf', path: '13/000/report.pdf', text: 'Quarterly revenue rose.'),
        ], text: 'Summarize.');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'Revenue is up.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('You are analyzing a document.', $system);
        $this->assertStringContainsString('=== FILE INFORMATION ===', $system);
        $this->assertStringContainsString('Filename: report.pdf', $system);
        $this->assertStringContainsString('Quarterly revenue rose.', $system);
        $this->assertStringNotContainsString('=== FILE 1 OF', $system);
    }

    /**
     * Streaming path must also receive every document's text — the
     * SSE chat route is what the production UI calls.
     */
    public function testStreamingMultipleDocumentsForwardsAllContent(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'a.pdf', type: 'pdf', path: '13/000/a.pdf', text: 'Alpha content.'),
            $this->buildFile(id: 2, name: 'b.pdf', type: 'pdf', path: '13/000/b.pdf', text: 'Beta content.'),
        ], text: '');

        $captured = null;
        $streamed = '';
        $this->aiFacade
            ->expects($this->once())
            ->method('chatStream')
            ->willReturnCallback(function (array $messages, callable $cb) use (&$captured) {
                $captured = $messages;
                $cb('Comparison...');

                return ['provider' => 'openai', 'model' => 'gpt-4'];
            });

        $sink = function (string $chunk) use (&$streamed): void {
            $streamed .= $chunk;
        };

        $result = $this->handler->handleStream($message, [], [], $sink);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Alpha content.', $captured[0]['content']);
        $this->assertStringContainsString('Beta content.', $captured[0]['content']);
        $this->assertSame('What is in these documents? Please summarize the content of each file.', $captured[1]['content']);
        $this->assertSame('Comparison...', $streamed);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
    }

    /**
     * If the user combines documents with a voice note, the spoken
     * transcript must reach the model alongside the document text.
     * Previously only the alphabetically-first file (or whichever
     * landed at index 0) made it through.
     */
    public function testMixedDocumentAndAudioMergesTranscriptIntoPrompt(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'spec.md', type: 'md', path: '13/000/spec.md', text: 'Spec body.'),
            $this->buildFile(id: 2, name: 'note.ogg', type: 'ogg', path: '13/000/note.ogg', text: 'Please double-check section three.'),
        ], text: 'Apply my comments.');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'Section three reviewed.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('Spec body.', $system);
        $this->assertStringContainsString('Voice message transcript:', $system);
        $this->assertStringContainsString('Please double-check section three.', $system);
        $this->assertSame('Apply my comments.', $captured[1]['content']);
    }

    /**
     * Two voice notes in one bubble used to drop the second
     * transcript on the floor. Both must now reach the LLM, labelled
     * so the model can tell them apart, while the conversational
     * audio system prompt is still used (no "you are analyzing a
     * document" regression).
     */
    public function testMultipleVoiceMessagesAreAllTranscribedIntoPrompt(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'first.ogg', type: 'ogg', path: '13/000/first.ogg', text: 'Hi, how are you?'),
            $this->buildFile(id: 2, name: 'second.ogg', type: 'ogg', path: '13/000/second.ogg', text: 'Also can you remind me tomorrow?'),
        ], text: '');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => "I'm well, sure!", 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('2 voice messages', $system);
        $this->assertStringContainsString('Voice message 1 (first.ogg)', $system);
        $this->assertStringContainsString('Voice message 2 (second.ogg)', $system);
        $this->assertStringContainsString('Hi, how are you?', $system);
        $this->assertStringContainsString('Also can you remind me tomorrow?', $system);
        $this->assertStringNotContainsString('You are analyzing a document', $system);

        $this->assertSame('voice_message_reply', $result['metadata']['analysis_type']);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
    }

    /**
     * Multi-image uploads previously stopped after the first vision
     * call. The handler must dispatch one vision call per image and
     * combine the per-image descriptions into a single labelled
     * response so the user sees every analysis.
     */
    public function testMultipleImagesAreAnalyzedAndAggregated(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'left.png', type: 'png', path: 'present/left.png'),
            $this->buildFile(id: 2, name: 'right.png', type: 'png', path: 'present/right.png'),
        ], text: 'What do you see?');

        $this->stubImagePathsExist(['present/left.png', 'present/right.png']);

        $calls = [];
        $this->aiFacade
            ->expects($this->exactly(2))
            ->method('analyzeImage')
            ->willReturnCallback(function (string $path) use (&$calls) {
                $calls[] = $path;

                return [
                    'content' => 'description for '.basename($path),
                    'provider' => 'openai',
                    'model' => 'gpt-4o',
                ];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertSame(['present/left.png', 'present/right.png'], $calls);
        $this->assertStringContainsString('### Image 1: left.png', $result['content']);
        $this->assertStringContainsString('description for left.png', $result['content']);
        $this->assertStringContainsString('### Image 2: right.png', $result['content']);
        $this->assertStringContainsString('description for right.png', $result['content']);
        $this->assertSame('vision', $result['metadata']['analysis_type']);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
    }

    /**
     * Copilot review on PR #986 flagged that the previous routing
     * happily fell through to the documents path as soon as ONE
     * document had extracted text — silently dropping every other
     * pending/failed attachment. For multi-file bubbles that was the
     * exact regression we'd just fixed for #978: "evaluate both files"
     * answers turning into "evaluated against whichever happened to be
     * ready first". The new routing surfaces the slowest-prepared
     * attachment instead so the user is told to wait, not lied to.
     */
    public function testMultiFileBubbleWithPendingDocumentRefusesPartialAnalysis(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'ready.md', type: 'md', path: '13/000/ready.md', text: 'Done.', status: 'processed'),
            $this->buildFile(id: 2, name: 'busy.pdf', type: 'pdf', path: '13/000/busy.pdf', text: '', status: 'extracting'),
        ], text: 'Compare both files please.');

        // No chat call must reach the LLM — we don't want to answer
        // half a question with the other half still extracting.
        $this->aiFacade->expects($this->never())->method('chat');
        $this->aiFacade->expects($this->never())->method('chatStream');

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('document_extraction_in_progress', $result['metadata']['error']);
        $this->assertStringContainsString('still being prepared', $result['content']);
    }

    /**
     * Same regression for audio: a mixed bubble with one transcribed
     * voice note and one whose transcription is still pending used
     * to silently reply to only the first. The transcript-missing
     * error must take priority so the user retries instead of
     * acting on incomplete input.
     */
    public function testMultiFileBubbleWithUntranscribedAudioRefusesPartialAnalysis(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'first.ogg', type: 'ogg', path: '13/000/first.ogg', text: 'Hello there.', status: 'processed'),
            $this->buildFile(id: 2, name: 'second.ogg', type: 'ogg', path: '13/000/second.ogg', text: '', status: 'processed'),
        ], text: '');

        $this->aiFacade->expects($this->never())->method('chat');
        $this->aiFacade->expects($this->never())->method('chatStream');

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('audio_not_transcribed', $result['metadata']['error']);
        $this->assertStringContainsString('Audio transcription failed', $result['content']);
    }

    /**
     * The mixed audio + document bubble must also be blocked when the
     * audio's transcript is missing — the document path used to win
     * priority and route directly to chat even though the spoken
     * content was lost.
     */
    public function testDocumentPlusUntranscribedAudioBlocksDocumentPath(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'spec.md', type: 'md', path: '13/000/spec.md', text: 'Spec body.', status: 'processed'),
            $this->buildFile(id: 2, name: 'note.ogg', type: 'ogg', path: '13/000/note.ogg', text: '', status: 'processed'),
        ], text: 'Apply the spoken changes to the spec.');

        $this->aiFacade->expects($this->never())->method('chat');
        $this->aiFacade->expects($this->never())->method('chatStream');

        $result = $this->handler->handle($message, [], []);

        $this->assertSame('audio_not_transcribed', $result['metadata']['error']);
    }

    /**
     * If one image vision call fails, the other should still be
     * delivered so the user gets a useful partial response instead
     * of a hard error covering both files.
     */
    public function testMultipleImagesRenderPartialResultsWhenOneFails(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'ok.png', type: 'png', path: 'present/ok.png'),
            $this->buildFile(id: 2, name: 'bad.png', type: 'png', path: 'present/bad.png'),
        ], text: '');

        $this->stubImagePathsExist(['present/ok.png', 'present/bad.png']);

        $this->aiFacade
            ->expects($this->exactly(2))
            ->method('analyzeImage')
            ->willReturnCallback(function (string $path) {
                if (str_contains($path, 'bad')) {
                    throw new \RuntimeException('boom');
                }

                return [
                    'content' => 'ok description',
                    'provider' => 'openai',
                    'model' => 'gpt-4o',
                ];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertStringContainsString('### Image 1: ok.png', $result['content']);
        $this->assertStringContainsString('ok description', $result['content']);
        $this->assertStringContainsString('### Image 2: bad.png', $result['content']);
        $this->assertStringContainsString('Image analysis failed: boom', $result['content']);
    }

    /**
     * Issue #983: a single video is analysed via the document/chat path
     * because the preprocessor already merged its transcript + visual
     * description into one block of text. The combined content must reach
     * the chat model.
     */
    public function testVideoIsAnalyzedAsDocumentWithCombinedText(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(
                id: 1,
                name: 'demo.mp4',
                type: 'mp4',
                path: '13/000/demo.mp4',
                text: "[Visual description]\nA dog runs across a lawn.\n\n[Audio transcript]\nGood boy!",
            ),
        ], text: 'What happens in this video?');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'A dog is playing.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('Filename: demo.mp4', $system);
        $this->assertStringContainsString('A dog runs across a lawn.', $system);
        $this->assertStringContainsString('Good boy!', $system);
        $this->assertSame('What happens in this video?', $captured[1]['content']);
        $this->assertSame('chat_with_extracted_text', $result['metadata']['analysis_type']);
    }

    /**
     * Issue #983: a video bundled with a document must be analysed
     * together with it (both blocks of text reach the model), reusing the
     * #978 multi-file document path.
     */
    public function testVideoPlusDocumentAreBundledTogether(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'brief.pdf', type: 'pdf', path: '13/000/brief.pdf', text: 'Launch on Friday.'),
            $this->buildFile(id: 2, name: 'teaser.mp4', type: 'mp4', path: '13/000/teaser.mp4', text: "[Visual description]\nA product reveal.\n\n[Audio transcript]\nComing soon."),
        ], text: 'Does the video match the brief?');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'Yes, they align.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('analyzing 2 documents', $system);
        $this->assertStringContainsString('Launch on Friday.', $system);
        $this->assertStringContainsString('A product reveal.', $system);
        $this->assertStringContainsString('Coming soon.', $system);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
    }

    /**
     * Issue #983: the exact screenshot scenario — image + video in one
     * message. The video is fully analysed and the image (which already
     * carries extracted text) is folded into the bundle instead of being
     * silently dropped.
     */
    public function testImagePlusVideoBundlesImageTextAndVideo(): void
    {
        $message = $this->buildMessageWithFiles([
            $this->buildFile(id: 1, name: 'receipt.png', type: 'png', path: '13/000/receipt.png', text: 'TOTAL: 42 EUR'),
            $this->buildFile(id: 2, name: 'clip.mp4', type: 'mp4', path: '13/000/clip.mp4', text: "[Visual description]\nHands holding a receipt.\n\n[Audio transcript]\nThat is the total."),
        ], text: 'Summarize what I sent.');

        $captured = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$captured) {
                $captured = $messages;

                return ['content' => 'A receipt totalling 42 EUR.', 'provider' => 'openai', 'model' => 'gpt-4'];
            });

        // Vision must NOT be called: the image rides along as text.
        $this->aiFacade->expects($this->never())->method('analyzeImage');

        $result = $this->handler->handle($message, [], []);

        $this->assertNotNull($captured);
        $system = $captured[0]['content'];

        $this->assertStringContainsString('Hands holding a receipt.', $system);
        $this->assertStringContainsString('TOTAL: 42 EUR', $system);
        $this->assertStringContainsString('receipt.png (image text)', $system);
        $this->assertSame(2, $result['metadata']['analyzed_file_count']);
        $this->assertSame('chat_with_extracted_text', $result['metadata']['analysis_type']);
    }

    /**
     * Build a Message mock that exposes the given files via getFiles().
     *
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
     * Make sure the upload paths used in the vision-path tests
     * actually exist on disk so `handleImagesWithVisionModel` does
     * not short-circuit with "file_not_found" before reaching the
     * provider call we want to assert on.
     *
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

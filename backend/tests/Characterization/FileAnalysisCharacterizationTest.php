<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\ChatErrorPresenter;
use App\Service\ModelConfigService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Support\FileAnalysisHandlerFactory;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * What FileAnalysisHandler does on both pipelines, per attachment mix.
 *
 * For every case `handle()` and `handleStream()` each record the calls that
 * reach AiFacade (method, messages, options), the chunks the stream callback
 * receives, the status events and the returned result — or the exception.
 * The two pipelines are expected to differ only in transport: content
 * returned versus streamed, `chat()` versus `chatStream()`.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class FileAnalysisCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/file_analysis.json';

    private string $uploadDir;

    /** @var list<array<string, mixed>> */
    private array $aiCalls = [];

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/synaplan-file-analysis-characterization-'.bin2hex(random_bytes(6));
        mkdir($this->uploadDir.'/13/000', 0o775, true);
        file_put_contents($this->uploadDir.'/13/000/photo.png', 'fake-image-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->uploadDir.'/13/000/photo.png');
        @rmdir($this->uploadDir.'/13/000');
        @rmdir($this->uploadDir.'/13');
        @rmdir($this->uploadDir);
    }

    public function testBothPipelinesMatchTheSnapshot(): void
    {
        $actual = [];
        foreach ($this->cases() as $name => $case) {
            $actual[$name] = [
                'buffered' => $this->runCase($case, false),
                'streamed' => $this->runCase($case, true),
            ];
        }

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'File analysis transcript');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cases(): array
    {
        $pdf = ['name' => 'report.pdf', 'type' => 'pdf', 'text' => 'Quarterly revenue rose by 12 percent.'];
        $ogg = ['name' => 'note.ogg', 'type' => 'ogg', 'text' => 'Hello there, what time is the meeting tomorrow?'];
        $mp4 = ['name' => 'clip.mp4', 'type' => 'mp4', 'text' => 'The speaker welcomes everyone to the launch.'];

        return [
            'one_document' => ['text' => 'Summarize.', 'files' => [$pdf]],
            'two_documents' => ['text' => 'Compare them.', 'files' => [
                ['name' => 'rules.md', 'type' => 'md', 'text' => 'Rule 1: ship small PRs.'],
                ['name' => 'plan.md', 'type' => 'md', 'text' => 'Step A: write tests.'],
            ]],
            'document_without_prompt' => ['text' => '', 'files' => [['name' => 'memo.docx', 'type' => 'docx', 'text' => 'Office closes at 5.']]],
            'voice_message' => ['text' => '', 'files' => [$ogg]],
            'two_voice_messages' => ['text' => '', 'files' => [$ogg, ['name' => 'second.ogg', 'type' => 'ogg', 'text' => 'And please bring the slides.']]],
            'voice_with_instruction' => ['text' => 'Translate this to German.', 'files' => [$ogg]],
            'single_image' => ['text' => 'What is on it?', 'files' => [['name' => 'photo.png', 'type' => 'png', 'text' => '']]],
            'images_one_missing' => ['text' => '', 'files' => [
                ['name' => 'photo.png', 'type' => 'png', 'text' => ''],
                ['name' => 'gone.jpg', 'type' => 'jpg', 'text' => ''],
            ]],
            'document_plus_voice' => ['text' => 'What do both say?', 'files' => [$pdf, $ogg]],
            'video_with_transcript' => ['text' => 'What happens in the video?', 'files' => [$mp4]],
            'image_text_in_bundle' => ['text' => 'Match the invoice.', 'files' => [$pdf, ['name' => 'photo.png', 'type' => 'png', 'text' => 'Invoice 42, total 99 EUR']]],
            'images_and_voice' => ['text' => '', 'files' => [['name' => 'photo.png', 'type' => 'png', 'text' => ''], $ogg]],
            'document_pending' => ['text' => 'Summarize.', 'files' => [['name' => 'big.pdf', 'type' => 'pdf', 'text' => '', 'status' => 'extracting']]],
            'document_failed' => ['text' => 'Summarize.', 'files' => [['name' => 'scan.pdf', 'type' => 'pdf', 'text' => '']]],
            'audio_pending' => ['text' => '', 'files' => [['name' => 'note.ogg', 'type' => 'ogg', 'text' => '', 'status' => 'uploaded']]],
            'audio_failed' => ['text' => '', 'files' => [['name' => 'note.ogg', 'type' => 'ogg', 'text' => '']]],
            'video_pending' => ['text' => '', 'files' => [['name' => 'clip.mp4', 'type' => 'mp4', 'text' => '', 'status' => 'extracting']]],
            'video_failed' => ['text' => '', 'files' => [['name' => 'clip.mp4', 'type' => 'mp4', 'text' => '']]],
            'unsupported_only' => ['text' => 'Open this.', 'files' => [['name' => 'archive.zip', 'type' => 'zip', 'text' => '']]],
            'no_file' => ['text' => 'Check the file.', 'files' => []],
            'follow_up_from_thread' => ['text' => 'Check the whole contract.', 'files' => [], 'thread_files' => [$pdf]],
            'provider_error' => ['text' => 'Summarize.', 'files' => [$pdf], 'provider_error' => true],
            'german_conversation' => ['text' => 'Fasse zusammen.', 'files' => [$pdf], 'classification' => ['language' => 'de']],
            'task_prompt_model' => ['text' => 'Summarize.', 'files' => [$pdf], 'classification' => ['prompt_metadata' => ['aiModel' => 99]]],
            'again_model' => ['text' => 'Hi', 'files' => [$ogg], 'classification' => ['model_id' => 55]],
        ];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function runCase(array $case, bool $streamed): array
    {
        $this->aiCalls = [];
        $chunks = [];
        $statuses = [];

        $handler = FileAnalysisHandlerFactory::create(
            $this->aiFacade(!empty($case['provider_error'])),
            $this->modelConfig(),
            new NullLogger(),
            $this->uploadDir,
            null,
            null,
            new ChatFailureClassifier(),
            new ChatErrorPresenter(new IdentityTranslator(), new ChatFailureClassifier()),
            new IdentityTranslator(),
        );

        $message = $this->message((string) $case['text'], $this->files($case['files']));
        $thread = isset($case['thread_files']) ? [$this->historyMessage($this->files($case['thread_files']))] : [];
        $classification = $case['classification'] ?? ['language' => 'en'];
        $progress = static function (array $update) use (&$statuses): void {
            $statuses[] = $update['status'].': '.$update['message'];
        };

        try {
            $result = $streamed
                ? $handler->handleStream($message, $thread, $classification, static function (mixed $chunk) use (&$chunks): void {
                    $chunks[] = $chunk;
                }, $progress)
                : $handler->handle($message, $thread, $classification, $progress);
        } catch (\Throwable $e) {
            $result = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return [
            'ai_calls' => $this->aiCalls,
            'stream_chunks' => $chunks,
            'statuses' => $statuses,
            'result' => $result,
        ];
    }

    private function aiFacade(bool $fail): AiFacade
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->method('chat')->willReturnCallback(function (array|string $messages, ?int $userId = null, array $options = []) use ($fail): array {
            $this->aiCalls[] = ['method' => 'chat', 'messages' => $messages, 'options' => $options];
            if ($fail) {
                throw new \RuntimeException('Provider unavailable');
            }

            return ['content' => 'Buffered answer.', 'provider' => 'openai', 'model' => 'gpt-4'];
        });
        $ai->method('chatStream')->willReturnCallback(function (array|string $messages, callable $streamCallback, ?int $userId = null, array $options = []) use ($fail): array {
            $this->aiCalls[] = ['method' => 'chatStream', 'messages' => $messages, 'options' => $options];
            if ($fail) {
                throw new \RuntimeException('Provider unavailable');
            }
            $streamCallback('Streamed ');
            $streamCallback('answer.');

            return ['provider' => 'openai', 'model' => 'gpt-4'];
        });
        $ai->method('analyzeImage')->willReturnCallback(function (string $path, string $prompt, ?int $userId = null, array $options = []): array {
            $this->aiCalls[] = ['method' => 'analyzeImage', 'path' => $path, 'prompt' => $prompt, 'options' => $options];

            return ['content' => 'A picture of '.basename($path).'.', 'provider' => 'openai', 'model' => 'gpt-4o'];
        });
        $ai->method('hasConfiguredSttProvider')->willReturn(false);

        return $ai;
    }

    private function modelConfig(): ModelConfigService
    {
        $config = $this->createMock(ModelConfigService::class);
        $config->method('getEffectiveUserIdForMessage')->willReturn(7);
        $config->method('getDefaultModel')->willReturnCallback(static fn (string $capability): ?int => match ($capability) {
            'ANALYZE' => 11,
            'CHAT' => 12,
            'PIC2TEXT' => 13,
            default => null,
        });
        $config->method('getProviderForModel')->willReturn('openai');
        $config->method('getModelName')->willReturnCallback(static fn (int $id): string => 'model-'.$id);

        return $config;
    }

    /**
     * @param list<array<string, mixed>> $specs
     *
     * @return list<File>
     */
    private function files(array $specs): array
    {
        $files = [];
        foreach ($specs as $index => $spec) {
            $file = $this->createMock(File::class);
            $file->method('getId')->willReturn($index + 1);
            $file->method('getFileName')->willReturn($spec['name']);
            $file->method('getFileType')->willReturn($spec['type']);
            $file->method('getFilePath')->willReturn('13/000/'.$spec['name']);
            $file->method('getFileText')->willReturn($spec['text']);
            $file->method('getStatus')->willReturn($spec['status'] ?? 'processed');
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @param list<File> $files
     */
    private function message(string $text, array $files): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(42);
        $message->method('getUserId')->willReturn(7);
        $message->method('getChatId')->willReturn(5);
        $message->method('getFiles')->willReturn(new ArrayCollection($files));
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFilePath')->willReturn('');
        $message->method('getFileText')->willReturn('');

        return $message;
    }

    /**
     * @param list<File> $files
     */
    private function historyMessage(array $files): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getDirection')->willReturn('IN');
        $message->method('getFiles')->willReturn(new ArrayCollection($files));
        $message->method('getFilePath')->willReturn('');

        return $message;
    }
}

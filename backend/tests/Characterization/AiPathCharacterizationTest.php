<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Tests\Characterization\Support\AiPathHarness;
use App\Tests\Characterization\Support\AiPathSnapshot;
use App\Tests\Characterization\Support\OfflineHttpGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of every AI call path in Synaplan (S1 AU9).
 *
 * One case per path of master plan F1: in-app stream, buffered chat (email),
 * widget stream, /v1/chat/completions buffered and streamed, /v1/embeddings,
 * /v1/images/generations, /v1/messages passthrough (Anthropic) and translated
 * (OpenAI, Gemini), sorter, memory extraction, vectorize, LLM rerank,
 * Perplexity web answer, media image, transcription, synthesis, model probe.
 *
 * Each case records the provider requests (ProviderHttpRecorder and
 * OpenAISdkRecorder, secrets masked), BUSELOG rows written (action, provider,
 * model, tokens, rounded cost, metadata), rate-limit checks made,
 * circuit-breaker and model-health calls, and the normalized value returned.
 *
 * Record with UPDATE_AI_PATH_SNAPSHOTS=1.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiPathCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/ai_paths.json';
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    /** @var list<string> */
    public const EXPECTED_PATHS = [
        'in_app_stream',
        'buffered_chat_email',
        'widget_stream',
        'openai_chat_buffered',
        'openai_chat_stream',
        'openai_embeddings',
        'openai_images_generations',
        'messages_passthrough_anthropic',
        'messages_translated_openai',
        'messages_translated_gemini',
        'sorter',
        'memory_extraction',
        'vectorize',
        'llm_rerank',
        'perplexity_web_answer',
        'media_image',
        'transcription',
        'synthesis',
        'model_probe',
    ];

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/ai-paths-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);
        file_put_contents($this->uploadDir.'/photo.png', (string) base64_decode(self::PIXEL, true));
        file_put_contents($this->uploadDir.'/voice.mp3', 'ID3-test-audio');
        OfflineHttpGuard::install();
    }

    protected function tearDown(): void
    {
        OfflineHttpGuard::uninstall();
        if (is_dir($this->uploadDir)) {
            $paths = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($paths as $path) {
                $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
            }
            rmdir($this->uploadDir);
        }
    }

    public function testEveryAiPathMatchesTheSnapshot(): void
    {
        $harness = new AiPathHarness($this, $this->uploadDir);
        $actual = $harness->runAll();

        self::assertSame([], OfflineHttpGuard::attempts(), 'An AI path attempted to reach the network.');
        self::assertSame(self::EXPECTED_PATHS, array_keys($actual));

        AiPathSnapshot::assertSame(self::SNAPSHOT, $actual, 'AI call paths');
    }
}

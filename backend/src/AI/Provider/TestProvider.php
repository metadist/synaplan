<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;

class TestProvider implements ChatProviderInterface, EmbeddingProviderInterface, VisionProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, FileAnalysisProviderInterface
{
    public function __construct(
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'test';
    }

    public function getDisplayName(): string
    {
        return 'Test Provider';
    }

    public function getDescription(): string
    {
        return 'Mock provider for testing and development';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'speech_to_text', 'text_to_speech', 'file_analysis'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => 'test-model',
            'embedding' => 'test-embedding',
        ];
    }

    public function getStatus(): array
    {
        return [
            'healthy' => true,
            'latency_ms' => 10,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getRequiredEnvVars(): array
    {
        return []; // Test provider requires no configuration
    }

    public function chat(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        $userContent = $lastMessage['content'] ?? 'hello';
        $userMessage = strtolower($userContent);

        // Search-query-style request (e.g. SearchQueryGenerator with tools:search prompt): return cleaned query like fallbackExtraction
        $systemContent = 'system' === $messages[0]['role'] ? ($messages[0]['content'] ?? '') : '';
        if (str_contains($systemContent, 'search') && str_contains($systemContent, 'query')) {
            return $this->mockSearchQueryExtraction($userContent);
        }

        // Image generation keywords
        if (preg_match('/(bild|image|picture|foto|photo|draw|zeichne|erstelle.*bild)/i', $userMessage)) {
            return "Here's your generated image!\n\n[IMAGE:https://picsum.photos/800/600]\n\nI've created a beautiful image for you using the TestProvider.";
        }

        // Video generation keywords
        if (preg_match('/(video|film|movie|clip|animation)/i', $userMessage)) {
            return "Here's your generated video!\n\n[VIDEO:https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4]\n\nI've created a short video for you using the TestProvider.";
        }

        // Different responses based on content
        $responses = [
            'hello' => "Hello! I'm the TestProvider. I can generate mock texts, images, and videos for you. Try asking me to create an image or video!",
            'how are you' => "I'm doing great! As a TestProvider, I'm always ready to help you test the system.",
            'what can you do' => "I can:\n\n• Generate mock text responses\n• Create mock images (try: 'create an image')\n• Generate mock videos (try: 'make a video')\n• Help you test the chat system!",
            // Support for smoke test prompts
            'smoke test' => 'success',
            'answer with "success"' => 'success',
        ];

        // Check for specific keywords
        foreach ($responses as $keyword => $response) {
            if (str_contains($userMessage, $keyword)) {
                return $response;
            }
        }

        // Default response with context
        $contextInfo = count($messages) > 1 ? ' (Message #'.count($messages).' in conversation)' : '';

        return "TestProvider response: I received your message '{$userMessage}'{$contextInfo}. This is a mock response to test the system. Try asking me to create an image or video!";
    }

    /**
     * Mock search-query extraction: same logic as SearchQueryGenerator::fallbackExtraction
     * so integration tests (SearchQueryGeneratorTest) pass with TestProvider.
     */
    private function mockSearchQueryExtraction(string $text): string
    {
        $text = preg_replace('/^\/(search|web|google|find)\s+/i', '', $text);
        $text = trim($text);
        if (preg_match('/^(["\'])(.+)\1$/s', $text, $matches)) {
            $text = $matches[2];
        }

        return $text;
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        $response = $this->chat($messages, $options);
        foreach (str_split($response, 10) as $chunk) {
            $callback($chunk);
            usleep(50000);
        }
    }

    // EmbeddingProviderInterface
    public function embed(string $text, array $options = []): array
    {
        // Fake 1536-dimensional embedding
        return array_fill(0, 1536, 0.123);
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        return array_map(fn ($t) => $this->embed($t, $options), $texts);
    }

    public function getDimensions(string $model): int
    {
        return 1536;
    }

    // VisionProviderInterface
    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        return "Test image description: A test image at {$imageUrl}";
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return 'Extracted text from test image';
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        return ['similarity' => 0.95, 'differences' => 'Test comparison'];
    }

    // ImageGenerationProviderInterface
    public function generateImage(string $prompt, array $options = []): array
    {
        // 1x1 red PNG pixel as data URL (68 bytes decoded)
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

        return [[
            'url' => 'data:image/png;base64,'.$pngBase64,
            'revised_prompt' => $prompt,
        ]];
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        $pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

        return array_fill(0, $count, 'data:image/png;base64,'.$pngBase64);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
    }

    // VideoGenerationProviderInterface
    public function generateVideo(string $prompt, array $options = []): array
    {
        // Minimal valid MP4 container (144 bytes) — ftyp + moov with 4s duration
        $mp4Base64 = 'AAAAGGZ0eXBtcDQyAAAAAG1wNDJpc29tAAAAeG1vb3YAAABwAAAAbG12aGQAAAAAAAAAAAAAAAAAAAPoAAAPoAABAAABAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAC';

        return [[
            'url' => 'data:video/mp4;base64,'.$mp4Base64,
            'revised_prompt' => $prompt,
            'duration' => 4,
        ]];
    }

    // SpeechToTextProviderInterface
    public function transcribe(string $audioPath, array $options = []): array
    {
        return [
            'text' => 'Test transcription',
            'language' => 'en',
            'duration' => 10.0,
        ];
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        return "Test audio translation to {$targetLang}";
    }

    // TextToSpeechProviderInterface
    public function synthesize(string $text, array $options = []): string
    {
        // Minimal valid MP3 frame (silence) written to uploadDir so AiFacade can move it
        $filename = 'tts_test_'.uniqid().'.mp3';
        $path = $this->uploadDir.'/'.$filename;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }

        // MPEG audio frame header (0xFFF3) + padding for a valid ~0.1s silent MP3
        $mp3 = base64_decode('//uQxAAAAAANIAAAAAExBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV');
        file_put_contents($path, $mp3);

        return $filename;
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        yield 'fake-audio-chunk-1';
        yield 'fake-audio-chunk-2';
    }

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/mpeg';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getVoices(): array
    {
        return [['id' => 'test', 'name' => 'Test Voice', 'language' => 'en']];
    }

    // FileAnalysisProviderInterface
    public function analyzeFile(string $filePath, string $fileType, array $options = []): array
    {
        return [
            'text' => 'Test file content',
            'summary' => 'Test summary',
            'metadata' => ['pages' => 1],
        ];
    }

    public function askAboutFile(string $filePath, string $question): string
    {
        return "Test answer to: {$question}";
    }
}

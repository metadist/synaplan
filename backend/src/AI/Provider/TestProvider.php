<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;

class TestProvider implements 
    ChatProviderInterface,
    EmbeddingProviderInterface,
    VisionProviderInterface,
    ImageGenerationProviderInterface,
    SpeechToTextProviderInterface,
    TextToSpeechProviderInterface,
    FileAnalysisProviderInterface
{
    public function getName(): string
    {
        return 'test';
    }
    
    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'speech_to_text', 'text_to_speech', 'file_analysis'];
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

    public function chat(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        $userMessage = strtolower($lastMessage['content'] ?? 'hello');

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
        ];

        // Check for specific keywords
        foreach ($responses as $keyword => $response) {
            if (str_contains($userMessage, $keyword)) {
                return $response;
            }
        }

        // Default response with context
        $contextInfo = count($messages) > 1 ? " (Message #" . count($messages) . " in conversation)" : "";
        return "TestProvider response: I received your message '{$userMessage}'{$contextInfo}. This is a mock response to test the system. Try asking me to create an image or video!";
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
        return array_map(fn($t) => $this->embed($t, $options), $texts);
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
        return "Extracted text from test image";
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        return ['similarity' => 0.95, 'differences' => 'Test comparison'];
    }

    // ImageGenerationProviderInterface
    public function generateImage(string $prompt, array $options = []): array
    {
        return [[
            'url' => 'https://via.placeholder.com/1024x1024?text=Test+Image',
            'revised_prompt' => $prompt,
        ]];
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        return array_fill(0, $count, 'https://via.placeholder.com/1024x1024');
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        return 'https://via.placeholder.com/1024x1024?text=Edited';
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
        return '/tmp/test_audio.mp3';
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


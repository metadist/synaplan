<?php

namespace App\AI\Provider;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VisionProviderInterface;

class TestProvider implements ChatProviderInterface, EmbeddingProviderInterface, VisionProviderInterface, ImageGenerationProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, FileAnalysisProviderInterface
{
    private const FAKE_TOKENS_PER_EMBED = 8;

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

    public function getRequiredEnvVars(): array
    {
        return []; // Test provider requires no configuration
    }

    public function chat(array $messages, array $options = []): array
    {
        $content = $this->generateContent($messages);
        $tokenEstimate = (int) ceil(strlen($content) / 4);

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => $tokenEstimate,
                'total_tokens' => 10 + $tokenEstimate,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ],
        ];
    }

    private function generateContent(array $messages): string
    {
        $lastMessage = end($messages);
        $userContent = $lastMessage['content'] ?? 'hello';
        $userMessage = strtolower($userContent);

        $systemContent = 'system' === $messages[0]['role'] ? ($messages[0]['content'] ?? '') : '';

        // Sort/classification prompt (tools:sort): return realistic JSON
        if (str_contains($systemContent, 'BTOPIC') && str_contains($systemContent, 'BWEBSEARCH')) {
            return $this->mockSortClassification($userContent, $systemContent);
        }

        // Multi-task planner prompt (tools:plan): return a schema-valid task plan.
        // Deterministic so E2E can exercise the multi-node DAG + task cards.
        if (str_contains($systemContent, 'Multi-Task Planner')) {
            return $this->mockTaskPlan($userContent);
        }

        // Search-query-style request (e.g. SearchQueryGenerator with tools:search prompt): return cleaned query like fallbackExtraction
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
     * Mock sort/classification: parse the user message JSON and return a
     * realistic classification response that MessageSorter::parseResponse()
     * can decode. Mirrors what a real LLM would return for the tools:sort
     * prompt: the same JSON object with BTOPIC, BLANG, BWEBSEARCH (and
     * optionally BMEDIA, BDURATION, BRESOLUTION, BINPUTMODE) updated.
     */
    private function mockSortClassification(string $userContent, string $systemContent): string
    {
        $data = json_decode($userContent, true);
        if (!is_array($data)) {
            return json_encode(['BTOPIC' => 'general', 'BLANG' => 'en', 'BWEBSEARCH' => 0], JSON_THROW_ON_ERROR);
        }

        $text = strtolower($data['BTEXT'] ?? '');
        $fileText = strtolower($data['BFILETEXT'] ?? '');

        $data['BLANG'] = $this->detectLanguage($text ?: $fileText);
        $data['BWEBSEARCH'] = $this->needsWebSearch($text) ? 1 : 0;

        $availableTopics = $this->extractAvailableTopics($systemContent);
        $classification = $this->classifyTopic($text ?: $fileText, $data, $availableTopics);

        $data['BTOPIC'] = $classification['topic'];

        if (isset($classification['media_type'])) {
            $data['BMEDIA'] = $classification['media_type'];
        }
        if (isset($classification['duration'])) {
            $data['BDURATION'] = $classification['duration'];
        }
        if (isset($classification['resolution'])) {
            $data['BRESOLUTION'] = $classification['resolution'];
        }
        if (isset($classification['input_mode'])) {
            $data['BINPUTMODE'] = $classification['input_mode'];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Mock task plan for the multi-task router (tools:plan prompt).
     *
     * A "summarize … and translate …" request yields a 2-node text chain
     * (summarize → translate → compose_reply) so E2E can verify the task cards
     * with deterministic, TTS-free streaming. Everything else returns a safe
     * single-node chat plan (executor then uses the legacy single-node path —
     * identical to a fallback, so existing tests are unaffected).
     */
    private function mockTaskPlan(string $userContent): string
    {
        $data = json_decode($userContent, true);
        $text = is_array($data) ? strtolower((string) ($data['BTEXT'] ?? '')) : strtolower($userContent);

        if (str_contains($text, 'summ') && str_contains($text, 'translat')) {
            return json_encode([
                'version' => 1,
                'language' => 'en',
                'reply_node' => 'n3',
                'tasks' => [
                    ['id' => 'n1', 'capability' => 'summarize', 'inputs' => ['text' => '$message.text']],
                    ['id' => 'n2', 'capability' => 'translate', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text'], 'params' => ['target' => 'de']],
                    ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text']],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'inputs' => ['text' => '$message.text']],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function detectLanguage(string $text): string
    {
        if ('' === $text) {
            return 'en';
        }

        $patterns = [
            'de' => '/\b(ich|und|der|die|das|ein|eine|ist|bitte|danke|erstelle|mache|kannst|hallo|wie|was|wer|wo|warum|nicht|auch|aber|oder|für|mit|von)\b/u',
            'fr' => '/\b(je|tu|il|elle|nous|vous|les|une|est|sont|avec|pour|dans|pas|merci|bonjour|oui|non|comment|pourquoi)\b/u',
            'es' => '/\b(yo|tú|él|ella|los|las|una|estoy|somos|para|con|por|hola|gracias|sí|cómo|qué|dónde|pero|también)\b/u',
            'it' => '/\b(io|tu|lui|lei|noi|gli|una|sono|siamo|per|con|ciao|grazie|come|cosa|dove|perché|anche|questo|quello)\b/u',
            'nl' => '/\b(ik|je|hij|zij|wij|het|een|zijn|hebben|voor|met|van|hallo|dank|hoe|wat|waar|waarom|niet|ook)\b/u',
            'pt' => '/\b(eu|tu|ele|ela|nós|uma|são|para|com|olá|obrigado|como|onde|porquê|também|este|esse)\b/u',
            'ru' => '/[а-яА-ЯёЁ]{3,}/u',
            'tr' => '/\b(ben|sen|bir|için|ile|merhaba|teşekkür|nasıl|neden|nerede|ama|veya|değil|var|yok|çok|bu|şu)\b/u',
            'sv' => '/\b(jag|du|han|hon|vi|det|ett|för|med|hej|tack|hur|vad|var|varför|inte|också|men|eller)\b/u',
        ];

        $scores = [];
        foreach ($patterns as $lang => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                $scores[$lang] = count($matches[0]);
            }
        }

        if (empty($scores)) {
            return 'en';
        }

        arsort($scores);

        return array_key_first($scores);
    }

    private function needsWebSearch(string $text): bool
    {
        return (bool) preg_match(
            '/\b(aktuell|current|news|wetter|weather|preis|price|heute|today|gestern|yesterday|2024|2025|2026|börse|stock|restaurant|öffnungszeiten|opening hours)\b/u',
            $text
        );
    }

    /**
     * Extract the list of valid topic slugs from the system prompt's
     * KEYLIST section (e.g. "general" | "mediamaker" | "coding").
     *
     * @return string[]
     */
    private function extractAvailableTopics(string $systemContent): array
    {
        if (preg_match_all('/"([^"]+)"/', $systemContent, $matches)) {
            $candidates = array_unique($matches[1]);

            return array_values(array_filter(
                $candidates,
                fn (string $t) => !in_array($t, ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr', 'image', 'video', 'audio', 'text_only', 'reference_images', '720p', '1080p', '4K'], true)
                    && !str_starts_with($t, 'tools:')
            ));
        }

        return ['general'];
    }

    /**
     * Classify the user text into a topic. Uses keyword matching against the
     * available topics and media-generation heuristics.
     *
     * @param string[] $availableTopics
     *
     * @return array{topic: string, media_type?: string, duration?: int, resolution?: string, input_mode?: string}
     */
    private function classifyTopic(string $text, array $data, array $availableTopics): array
    {
        $hasMediamaker = in_array('mediamaker', $availableTopics, true);

        if ($hasMediamaker) {
            $mediaResult = $this->detectMediaIntent($text, $data);
            if (null !== $mediaResult) {
                return $mediaResult;
            }
        }

        return ['topic' => $data['BTOPIC'] ?: 'general'];
    }

    /**
     * Detect media generation intent and return structured classification,
     * or null when the message is not a media request.
     *
     * @return array{topic: string, media_type: string, duration?: int, resolution?: string, input_mode?: string}|null
     */
    private function detectMediaIntent(string $text, array $data): ?array
    {
        $isVideoRequest = (bool) preg_match('/\b(video|film|clip|animation|movie)\b/u', $text);
        $isAudioRequest = (bool) preg_match('/\b(sprich|vorlesen|lies vor|speak|tts|text.to.speech|vertone|audio|aloud)\b/u', $text)
            || (bool) preg_match('/\bread\b.*\b(aloud|vor)\b/u', $text);
        $isImageGenRequest = (bool) preg_match('/\b(erstelle.*bild|generate.*image|create.*image|create.*picture|mache.*foto|draw|zeichne|illustr|render|design.*logo|bild.*erstellen|image.*generat)\b/u', $text);

        $hasImageAttachments = false;
        if (!empty($data['BATTACHED_FILES'])) {
            $hasImageAttachments = (bool) preg_match('/\b(jpg|jpeg|png|gif|webp)\b/i', $data['BATTACHED_FILES']);
        }
        if (!$hasImageAttachments && !empty($data['BFILETYPE'])) {
            $hasImageAttachments = (bool) preg_match('/^image\//i', $data['BFILETYPE']);
        }

        $isImageEditRequest = $hasImageAttachments && (bool) preg_match(
            '/\b(edit|bearbeit|combine|kombin|merge|blend|replace|ersetze|style|transform|mach.*daraus|put.*into|füge.*ein)\b/u',
            $text
        );

        if ($isVideoRequest) {
            $result = ['topic' => 'mediamaker', 'media_type' => 'video'];

            if (preg_match('/(\d+)\s*(?:sekund|second|sec|s\b)/u', $text, $m)) {
                $duration = (int) $m[1];
                $result['duration'] = max(4, min(8, $duration));
            }

            if (preg_match('/\b(720p?|1080p?|4k|uhd|hd|fullhd|full.hd)\b/ui', $text, $m)) {
                $result['resolution'] = $this->mapResolution($m[1]);
            }

            return $result;
        }

        if ($isAudioRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'audio'];
        }

        if ($isImageEditRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'image', 'input_mode' => 'reference_images'];
        }

        if ($isImageGenRequest) {
            return ['topic' => 'mediamaker', 'media_type' => 'image', 'input_mode' => 'text_only'];
        }

        return null;
    }

    private function mapResolution(string $raw): string
    {
        $key = strtolower(preg_replace('/[\s\-_]+/', '', $raw));

        return match ($key) {
            '720', '720p', 'hd' => '720p',
            '1080', '1080p', 'fhd', 'fullhd' => '1080p',
            '4k', 'uhd' => '4K',
            default => '1080p',
        };
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

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $result = $this->chat($messages, $options);
        foreach (str_split($result['content'], 10) as $chunk) {
            $callback($chunk);
            usleep(50000);
        }

        return ['usage' => $result['usage']];
    }

    // EmbeddingProviderInterface
    public function embed(string $text, array $options = []): array
    {
        return [
            'embedding' => array_fill(0, 1024, 0.123),
            'usage' => [
                'prompt_tokens' => self::FAKE_TOKENS_PER_EMBED,
                'total_tokens' => self::FAKE_TOKENS_PER_EMBED,
            ],
        ];
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $embeddings = [];
        foreach ($texts as $t) {
            $embeddings[] = $this->embed($t, $options)['embedding'];
        }

        $promptTokens = self::FAKE_TOKENS_PER_EMBED * count($texts);

        return [
            'embeddings' => $embeddings,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'total_tokens' => $promptTokens,
            ],
        ];
    }

    public function getDimensions(string $model): int
    {
        return 1024;
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
        $label = empty($options['images']) ? 'Test+Image' : 'Test+Pic2Pic';

        return [[
            'url' => 'https://via.placeholder.com/1024x1024?text='.$label,
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

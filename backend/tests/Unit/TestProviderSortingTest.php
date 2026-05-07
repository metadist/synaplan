<?php

namespace App\Tests\Unit;

use App\AI\Provider\TestProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that TestProvider returns realistic sorting/classification JSON
 * when invoked with a tools:sort-style system prompt, so that
 * MessageSorter::parseResponse() can decode the response instead of
 * falling back to defaults.
 */
class TestProviderSortingTest extends TestCase
{
    private TestProvider $provider;
    private string $sortSystemPrompt;

    protected function setUp(): void
    {
        $this->provider = new TestProvider();

        $this->sortSystemPrompt = <<<'PROMPT'
You are an assistant of assistants. You sort user requests by setting JSON values only.
Classify the user's message into one of these BTOPIC categories:
- "general": General questions and conversation
- "mediamaker": Image, video, or audio generation
- "coding": Programming and development questions
Set BWEBSEARCH to 1 if the user needs current information, otherwise 0.
Answer format: "BTOPIC": "general" | "mediamaker" | "coding"
"BLANG": "de" | "en" | "it" | "es" | "fr" | "nl" | "pt" | "ru" | "sv" | "tr"
"BWEBSEARCH": 0 | 1
PROMPT;
    }

    private function classifyMessage(string $text, string $lang = 'en', string $topic = '', array $extra = []): array
    {
        $messageData = array_merge([
            'BTEXT' => $text,
            'BLANG' => $lang,
            'BTOPIC' => $topic,
            'BFILETEXT' => '',
            'BWEBSEARCH' => 0,
        ], $extra);

        $messages = [
            ['role' => 'system', 'content' => $this->sortSystemPrompt],
            ['role' => 'user', 'content' => json_encode($messageData, JSON_UNESCAPED_UNICODE)],
        ];

        $result = $this->provider->chat($messages);

        $decoded = json_decode($result['content'], true);
        $this->assertIsArray($decoded, 'Sort response must be valid JSON, got: '.$result['content']);

        return $decoded;
    }

    // ================================================
    // Response format
    // ================================================

    public function testSortResponseIsValidJson(): void
    {
        $result = $this->classifyMessage('Hello, how are you?');

        $this->assertArrayHasKey('BTOPIC', $result);
        $this->assertArrayHasKey('BLANG', $result);
        $this->assertArrayHasKey('BWEBSEARCH', $result);
    }

    public function testSortResponsePreservesOriginalFields(): void
    {
        $result = $this->classifyMessage('Hello', 'en', 'general', [
            'BDATETIME' => '2026-05-07 10:00:00',
            'BFILEPATH' => '/some/path',
        ]);

        $this->assertSame('2026-05-07 10:00:00', $result['BDATETIME']);
        $this->assertSame('/some/path', $result['BFILEPATH']);
    }

    // ================================================
    // Language detection
    // ================================================

    #[DataProvider('languageDetectionProvider')]
    public function testDetectsLanguageCorrectly(string $text, string $expectedLang): void
    {
        $result = $this->classifyMessage($text);

        $this->assertSame($expectedLang, $result['BLANG']);
    }

    public static function languageDetectionProvider(): array
    {
        return [
            'German' => ['Kannst du mir bitte helfen? Ich habe eine Frage.', 'de'],
            'English' => ['Can you help me with this question?', 'en'],
            'French' => ['Bonjour, comment allez-vous aujourd\'hui?', 'fr'],
            'Spanish' => ['Hola, cómo estoy para hacer esto?', 'es'],
            'Turkish' => ['Merhaba, ben bir soru sormak istiyorum', 'tr'],
            'Italian' => ['Ciao, come siamo noi oggi?', 'it'],
            'unknown fallback' => ['xyzzy 12345 foo', 'en'],
        ];
    }

    // ================================================
    // Topic classification — general
    // ================================================

    public function testClassifiesGenericQuestionAsGeneral(): void
    {
        $result = $this->classifyMessage('What is the meaning of life?');

        $this->assertSame('general', $result['BTOPIC']);
    }

    public function testPreservesExistingTopicForGenericMessage(): void
    {
        $result = $this->classifyMessage('Tell me more about that', topic: 'coding');

        $this->assertSame('coding', $result['BTOPIC']);
    }

    // ================================================
    // Topic classification — mediamaker (video)
    // ================================================

    #[DataProvider('videoRequestProvider')]
    public function testClassifiesVideoRequestAsMediamaker(string $text): void
    {
        $result = $this->classifyMessage($text);

        $this->assertSame('mediamaker', $result['BTOPIC']);
        $this->assertSame('video', $result['BMEDIA']);
    }

    public static function videoRequestProvider(): array
    {
        return [
            'English' => ['Create a video of a sunset over the ocean'],
            'German' => ['Erstelle ein Video von einem Hund im Park'],
        ];
    }

    public function testExtractsVideoDuration(): void
    {
        $result = $this->classifyMessage('Make a 6 second video of a running dog');

        $this->assertSame('mediamaker', $result['BTOPIC']);
        $this->assertSame('video', $result['BMEDIA']);
        $this->assertSame(6, $result['BDURATION']);
    }

    public function testClampsDurationToMaximum(): void
    {
        $result = $this->classifyMessage('Create a 30 second video of fireworks');

        $this->assertSame(8, $result['BDURATION']);
    }

    public function testExtractsVideoResolution(): void
    {
        $result = $this->classifyMessage('Generate a 4k video of a car race');

        $this->assertSame('4K', $result['BRESOLUTION']);
    }

    // ================================================
    // Topic classification — mediamaker (image)
    // ================================================

    #[DataProvider('imageGenerationProvider')]
    public function testClassifiesImageGenerationAsMediamaker(string $text): void
    {
        $result = $this->classifyMessage($text);

        $this->assertSame('mediamaker', $result['BTOPIC']);
        $this->assertSame('image', $result['BMEDIA']);
        $this->assertSame('text_only', $result['BINPUTMODE']);
    }

    public static function imageGenerationProvider(): array
    {
        return [
            'English' => ['Create an image of a mountain landscape'],
            'German' => ['Erstelle ein Bild von einem Sonnenuntergang'],
        ];
    }

    public function testClassifiesImageEditWithAttachmentAsMediamaker(): void
    {
        $result = $this->classifyMessage(
            'Edit this photo and replace the background',
            extra: ['BATTACHED_FILES' => 'photo.jpg', 'BATTACHED_COUNT' => 1]
        );

        $this->assertSame('mediamaker', $result['BTOPIC']);
        $this->assertSame('image', $result['BMEDIA']);
        $this->assertSame('reference_images', $result['BINPUTMODE']);
    }

    public function testImageDescriptionWithAttachmentStaysGeneral(): void
    {
        $result = $this->classifyMessage(
            'What is shown in this image?',
            extra: ['BATTACHED_FILES' => 'photo.jpg', 'BATTACHED_COUNT' => 1]
        );

        $this->assertSame('general', $result['BTOPIC']);
        $this->assertArrayNotHasKey('BMEDIA', $result);
    }

    // ================================================
    // Topic classification — mediamaker (audio)
    // ================================================

    public function testClassifiesAudioRequestAsMediamaker(): void
    {
        $result = $this->classifyMessage('Read this text aloud please');

        $this->assertSame('mediamaker', $result['BTOPIC']);
        $this->assertSame('audio', $result['BMEDIA']);
    }

    // ================================================
    // Web search detection
    // ================================================

    #[DataProvider('webSearchProvider')]
    public function testWebSearchDetection(string $text, int $expected): void
    {
        $result = $this->classifyMessage($text);

        $this->assertSame($expected, $result['BWEBSEARCH']);
    }

    public static function webSearchProvider(): array
    {
        return [
            'current events EN' => ['What is the current weather in Berlin?', 1],
            'current events DE' => ['Wie ist das aktuelle Wetter in München?', 1],
            'general question' => ['Explain quantum physics', 0],
        ];
    }

    // ================================================
    // Edge cases
    // ================================================

    public function testHandlesEmptyText(): void
    {
        $result = $this->classifyMessage('');

        $this->assertSame('general', $result['BTOPIC']);
        $this->assertSame('en', $result['BLANG']);
    }

    public function testHandlesNonJsonUserMessage(): void
    {
        $messages = [
            ['role' => 'system', 'content' => $this->sortSystemPrompt],
            ['role' => 'user', 'content' => 'This is not JSON at all'],
        ];

        $result = $this->provider->chat($messages);
        $decoded = json_decode($result['content'], true);

        $this->assertIsArray($decoded);
        $this->assertSame('general', $decoded['BTOPIC']);
        $this->assertSame('en', $decoded['BLANG']);
    }

    public function testDoesNotTriggerSortForNormalChat(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello!'],
        ];

        $result = $this->provider->chat($messages);

        $this->assertNull(json_decode($result['content'], true),
            'Normal chat should NOT return JSON sort response');
    }
}

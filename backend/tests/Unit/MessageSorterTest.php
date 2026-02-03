<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Repository\PromptRepository;
use App\Service\DiscordNotificationService;
use App\Service\Message\MessageSorter;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageSorterTest extends TestCase
{
    private MessageSorter $sorter;
    private \ReflectionMethod $parseResponseMethod;
    private \ReflectionMethod $normalizeMediaTypeMethod;

    protected function setUp(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $promptRepository = $this->createMock(PromptRepository::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $promptService = $this->createMock(PromptService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $discord = $this->createMock(DiscordNotificationService::class);

        $this->sorter = new MessageSorter(
            $aiFacade,
            $promptRepository,
            $modelConfigService,
            $promptService,
            $logger,
            $discord
        );

        // Make private methods accessible for testing
        $reflection = new \ReflectionClass($this->sorter);

        $this->parseResponseMethod = $reflection->getMethod('parseResponse');
        $this->parseResponseMethod->setAccessible(true);

        $this->normalizeMediaTypeMethod = $reflection->getMethod('normalizeMediaType');
        $this->normalizeMediaTypeMethod->setAccessible(true);
    }

    // ===========================================
    // normalizeMediaType tests
    // ===========================================

    #[DataProvider('audioMediaTypeProvider')]
    public function testNormalizeMediaTypeReturnsAudioForAudioVariations(string $input): void
    {
        $result = $this->normalizeMediaTypeMethod->invoke($this->sorter, $input);
        $this->assertSame('audio', $result);
    }

    public static function audioMediaTypeProvider(): array
    {
        return [
            'audio' => ['audio'],
            'AUDIO uppercase' => ['AUDIO'],
            'sound' => ['sound'],
            'voice' => ['voice'],
            'tts' => ['tts'],
            'text2sound' => ['text2sound'],
            'speech' => ['speech'],
            'with whitespace' => [' audio '],
        ];
    }

    #[DataProvider('videoMediaTypeProvider')]
    public function testNormalizeMediaTypeReturnsVideoForVideoVariations(string $input): void
    {
        $result = $this->normalizeMediaTypeMethod->invoke($this->sorter, $input);
        $this->assertSame('video', $result);
    }

    public static function videoMediaTypeProvider(): array
    {
        return [
            'video' => ['video'],
            'VIDEO uppercase' => ['VIDEO'],
            'vid' => ['vid'],
            'text2vid' => ['text2vid'],
            'film' => ['film'],
            'clip' => ['clip'],
            'animation' => ['animation'],
            'with whitespace' => [' video '],
        ];
    }

    #[DataProvider('imageMediaTypeProvider')]
    public function testNormalizeMediaTypeReturnsImageForImageVariations(string $input): void
    {
        $result = $this->normalizeMediaTypeMethod->invoke($this->sorter, $input);
        $this->assertSame('image', $result);
    }

    public static function imageMediaTypeProvider(): array
    {
        return [
            'image' => ['image'],
            'IMAGE uppercase' => ['IMAGE'],
            'img' => ['img'],
            'picture' => ['picture'],
            'pic' => ['pic'],
            'text2pic' => ['text2pic'],
            'photo' => ['photo'],
            'with whitespace' => [' image '],
        ];
    }

    #[DataProvider('invalidMediaTypeProvider')]
    public function testNormalizeMediaTypeReturnsNullForInvalidValues(string $input): void
    {
        $result = $this->normalizeMediaTypeMethod->invoke($this->sorter, $input);
        $this->assertNull($result);
    }

    public static function invalidMediaTypeProvider(): array
    {
        return [
            'empty string' => [''],
            'unknown type' => ['document'],
            'random text' => ['foobar'],
            'number' => ['123'],
        ];
    }

    // ===========================================
    // parseResponse BMEDIA tests
    // ===========================================

    public function testParseResponseExtractsBMediaCorrectly(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('en', $result['language']);
        $this->assertSame('video', $result['media_type']);
    }

    public function testParseResponseNormalizesBMediaVariations(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "de", "BMEDIA": "film"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('video', $result['media_type']); // 'film' normalized to 'video'
    }

    public function testParseResponseReturnsNullMediaTypeWhenMissing(): void
    {
        $response = '{"BTOPIC": "general", "BLANG": "en"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['media_type']);
    }

    public function testParseResponseReturnsNullMediaTypeForInvalidValue(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "invalid"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['media_type']);
    }

    // ===========================================
    // parseResponse BDURATION tests
    // ===========================================

    public function testParseResponseExtractsBDurationCorrectly(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": 6}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame(6, $result['duration']);
    }

    public function testParseResponseAcceptsStringDuration(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": "10"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame(10, $result['duration']);
    }

    public function testParseResponseAcceptsMinDuration(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": 1}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame(1, $result['duration']);
    }

    public function testParseResponseAcceptsMaxDuration(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": 120}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame(120, $result['duration']);
    }

    public function testParseResponseRejectsZeroDuration(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": 0}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['duration']);
    }

    public function testParseResponseRejectsNegativeDuration(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": -5}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['duration']);
    }

    public function testParseResponseRejectsDurationOver120(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": 121}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['duration']);
    }

    public function testParseResponseReturnsNullDurationWhenMissing(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['duration']);
    }

    public function testParseResponseReturnsNullDurationForNonNumericValue(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BDURATION": "five"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['duration']);
    }

    // ===========================================
    // parseResponse JSON fallback tests
    // ===========================================

    public function testParseResponseHandlesJsonWithCodeBlock(): void
    {
        $response = "```json\n{\"BTOPIC\": \"mediamaker\", \"BLANG\": \"de\", \"BMEDIA\": \"video\", \"BDURATION\": 8}\n```";
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertSame('video', $result['media_type']);
        $this->assertSame(8, $result['duration']);
    }

    public function testParseResponseFallsBackToOriginalOnInvalidJson(): void
    {
        $response = 'This is not valid JSON';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'de'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertNull($result['media_type']);
        $this->assertNull($result['duration']);
    }

    // ===========================================
    // Combined media type and duration test
    // ===========================================

    public function testParseResponseHandlesCompleteVideoClassification(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "de", "BWEBSEARCH": 0, "BMEDIA": "video", "BDURATION": 6}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertFalse($result['web_search']);
        $this->assertSame('video', $result['media_type']);
        $this->assertSame(6, $result['duration']);
    }
}

<?php

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\DiscordNotificationService;
use App\Service\Message\MessageSorter;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
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
        $rateLimitService = $this->createMock(RateLimitService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $discord = $this->createMock(DiscordNotificationService::class);

        $this->sorter = new MessageSorter(
            $aiFacade,
            $promptRepository,
            $modelConfigService,
            $promptService,
            $rateLimitService,
            $em,
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
        $this->assertNull($result['input_mode']);
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
        $this->assertNull($result['input_mode']);
        $this->assertNull($result['resolution']);
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
        $this->assertNull($result['input_mode']);
        $this->assertNull($result['resolution']);
    }

    // ===========================================
    // parseResponse BINPUTMODE tests
    // ===========================================

    public function testParseResponseExtractsBInputModeCorrectly(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BINPUTMODE": "reference_images"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('reference_images', $result['input_mode']);
    }

    public function testParseResponseRejectsInvalidBInputMode(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BINPUTMODE": "invalid_mode"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['input_mode']);
    }

    // ===========================================
    // parseResponse BRESOLUTION tests
    // ===========================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalResolutionProvider(): array
    {
        return [
            '720p exact' => ['720p', '720p'],
            '1080p exact' => ['1080p', '1080p'],
            '4K exact' => ['4K', '4K'],
        ];
    }

    #[DataProvider('canonicalResolutionProvider')]
    public function testParseResponseAcceptsCanonicalResolution(string $input, string $expected): void
    {
        $response = sprintf('{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BRESOLUTION": "%s"}', $input);
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame($expected, $result['resolution']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function resolutionAliasProvider(): array
    {
        return [
            // 4K family
            'lowercase 4k' => ['4k', '4K'],
            'with space 4 k' => ['4 k', '4K'],
            'uhd' => ['uhd', '4K'],
            'UHD uppercase' => ['UHD', '4K'],
            'ultra hd' => ['ultra hd', '4K'],
            'ultrahd' => ['ultrahd', '4K'],
            'res 2160p' => ['2160p', '4K'],
            'res 2160' => ['2160', '4K'],
            // 1080p family
            'fhd' => ['fhd', '1080p'],
            'full hd' => ['full hd', '1080p'],
            'fullhd' => ['fullhd', '1080p'],
            'res 1080' => ['1080', '1080p'],
            // 720p family
            'hd' => ['hd', '720p'],
            'res 720' => ['720', '720p'],
            // Unsupported tiers must clamp to a supported value
            '8k clamps up to 4K' => ['8k', '4K'],
            '5k clamps up to 4K' => ['5k', '4K'],
            '1440p clamps to 1080p' => ['1440p', '1080p'],
            'qhd clamps to 1080p' => ['qhd', '1080p'],
            '2k clamps to 1080p' => ['2k', '1080p'],
            // Whitespace tolerance
            'leading and trailing whitespace' => [' 4K ', '4K'],
        ];
    }

    #[DataProvider('resolutionAliasProvider')]
    public function testParseResponseNormalizesResolutionAliases(string $input, string $expected): void
    {
        $response = sprintf('{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BRESOLUTION": "%s"}', $input);
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame($expected, $result['resolution']);
    }

    public function testParseResponseReturnsNullResolutionWhenMissing(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['resolution']);
    }

    public function testParseResponseReturnsNullResolutionForUnknownAlias(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BRESOLUTION": "potato"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        // Unrecognised value drops to null so MediaGenerationService applies
        // the configured default (1080p) instead of forwarding garbage.
        $this->assertNull($result['resolution']);
    }

    public function testParseResponseReturnsNullResolutionForEmptyString(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BRESOLUTION": ""}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertNull($result['resolution']);
    }

    public function testParseResponseAcceptsIntegerResolutionShortcut(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "en", "BMEDIA": "video", "BRESOLUTION": 1080}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        // Some models emit the resolution as a bare integer (1080, 2160…); we
        // still want it to resolve to the canonical string.
        $this->assertSame('1080p', $result['resolution']);
    }

    // ===========================================
    // Combined classification test
    // ===========================================

    public function testParseResponseHandlesCompleteClassification(): void
    {
        $response = '{"BTOPIC": "mediamaker", "BLANG": "de", "BWEBSEARCH": 0, "BMEDIA": "video", "BDURATION": 6, "BRESOLUTION": "4K", "BINPUTMODE": "text_only"}';
        $originalData = ['BTOPIC' => 'general', 'BLANG' => 'en'];

        $result = $this->parseResponseMethod->invoke($this->sorter, $response, $originalData);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertFalse($result['web_search']);
        $this->assertSame('video', $result['media_type']);
        $this->assertSame(6, $result['duration']);
        $this->assertSame('4K', $result['resolution']);
        $this->assertSame('text_only', $result['input_mode']);
    }

    // ===========================================
    // BMULTI (multi-step vote)
    // ===========================================

    /**
     * @return array<string, array{0: string, 1: bool|null}>
     */
    public static function multiStepProvider(): array
    {
        return [
            'integer one' => ['{"BTOPIC": "general", "BMULTI": 1}', true],
            'integer zero' => ['{"BTOPIC": "general", "BMULTI": 0}', false],
            'boolean true' => ['{"BTOPIC": "general", "BMULTI": true}', true],
            'boolean false' => ['{"BTOPIC": "general", "BMULTI": false}', false],
            'string one' => ['{"BTOPIC": "general", "BMULTI": "1"}', true],
            'string true' => ['{"BTOPIC": "general", "BMULTI": "true"}', true],
            // No vote must stay null so the planner keeps deciding — an absent
            // field is NOT the same as "single step".
            'absent' => ['{"BTOPIC": "general"}', null],
            'null' => ['{"BTOPIC": "general", "BMULTI": null}', null],
            'garbage' => ['{"BTOPIC": "general", "BMULTI": "maybe"}', null],
        ];
    }

    #[DataProvider('multiStepProvider')]
    public function testParseResponseReadsTheMultiStepVote(string $response, ?bool $expected): void
    {
        $result = $this->parseResponseMethod->invoke(
            $this->sorter,
            $response,
            ['BTOPIC' => 'general', 'BLANG' => 'en'],
        );

        $this->assertSame($expected, $result['multi_step']);
    }

    public function testParseResponseFallbackCarriesNoMultiStepVote(): void
    {
        $result = $this->parseResponseMethod->invoke(
            $this->sorter,
            'not json at all',
            ['BTOPIC' => 'general', 'BLANG' => 'en'],
        );

        $this->assertNull($result['multi_step']);
    }

    // ===========================================
    // Completion budget
    // ===========================================

    public function testClassificationCallLeavesRoomForReasoningTokens(): void
    {
        // The SORT binding is routinely a reasoning model, which spends
        // completion budget on thinking tokens before the JSON starts. A cap
        // that runs out mid-object drops the turn to topic `general` with no
        // BWEBSEARCH and no BMULTI vote, and the only symptom is a log line.
        $aiFacade = $this->createMock(AiFacade::class);
        $promptRepository = $this->createMock(PromptRepository::class);

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('SORT. Topics: [DYNAMICLIST] Keys: [KEYLIST] Langs: [LANGLIST]');
        $promptRepository->expects($this->any())->method('findByTopic')->with('tools:sort', 0)->willReturn($prompt);
        $promptRepository->method('getAllTopics')->willReturn(['general']);
        $promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'catch-all'],
        ]);

        $options = null;
        $aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"BTOPIC":"general","BLANG":"en"}', 'provider' => 'groq'];
            }
        );

        $sorter = new MessageSorter(
            $aiFacade,
            $promptRepository,
            $this->createMock(ModelConfigService::class),
            $this->createMock(PromptService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DiscordNotificationService::class),
        );

        // A null user id skips the rule-based routing lookup and the usage record.
        $sorter->classify(['BTEXT' => 'hello', 'BLANG' => 'en', 'BTOPIC' => ''], [], null);

        $this->assertGreaterThanOrEqual(2048, $options['max_tokens'] ?? 0);
    }

    /**
     * The sorter used to see history as pure text, so a picture generated two
     * turns ago was invisible and "make the car blue" looked like a brand new
     * image request. Each history turn carrying a file now gets a compact note.
     */
    public function testHistoryNotesTheFilesEarlierTurnsCarry(): void
    {
        $sent = $this->captureSorterMessages([
            (new Message())->setDirection('IN')->setText('generate a red sports car'),
            (new Message())->setDirection('OUT')->setText('Here is your image')->setFilePath('ai/car-sunset.png'),
        ]);

        $assistantTurn = $this->turnByRole($sent, 'assistant');
        $this->assertStringContainsString('[Generated image file: car-sunset.png]', $assistantTurn);
    }

    public function testHistoryNotesDistinguishUploadsFromGeneratedFiles(): void
    {
        $sent = $this->captureSorterMessages([
            (new Message())->setDirection('IN')->setText('what is in here?')->setFilePath('uploads/contract.pdf'),
        ]);

        $this->assertStringContainsString('[Uploaded document file: contract.pdf]', $this->turnByRole($sent, 'user'));
    }

    public function testTurnsWithoutFilesAreNotAnnotated(): void
    {
        $sent = $this->captureSorterMessages([
            (new Message())->setDirection('OUT')->setText('Sure, here is the answer.'),
        ]);

        $this->assertStringNotContainsString('[Generated', $this->turnByRole($sent, 'assistant'));
    }

    /**
     * The notes ride inside the existing history window, so a long thread full
     * of files must not grow it without bound.
     */
    public function testFileNotesStayInsideTheirBudget(): void
    {
        $history = [];
        for ($i = 0; $i < 40; ++$i) {
            $history[] = (new Message())->setDirection('OUT')->setText('done')->setFilePath('ai/render-'.$i.'.png');
        }

        $sent = $this->captureSorterMessages($history);

        $annotated = 0;
        foreach ($sent as $entry) {
            $annotated += mb_substr_count((string) $entry['content'], '[Generated image file:');
        }

        $this->assertGreaterThan(0, $annotated);
        $this->assertLessThan(40, $annotated, 'the annotation budget must stop before the whole thread is annotated');
    }

    /**
     * @param list<Message> $history
     *
     * @return list<array{role: string, content: string}>
     */
    private function captureSorterMessages(array $history): array
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $promptRepository = $this->createMock(PromptRepository::class);

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getPrompt')->willReturn('SORT [DYNAMICLIST] [KEYLIST] [LANGLIST]');
        $promptRepository->expects($this->any())->method('findByTopic')->with('tools:sort', 0)->willReturn($prompt);
        $promptRepository->method('getAllTopics')->willReturn(['general', 'mediamaker']);
        $promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'catch-all'],
        ]);

        $sent = [];
        $aiFacade->method('chat')->willReturnCallback(
            function (array $messages) use (&$sent): array {
                $sent = $messages;

                return ['content' => '{"BTOPIC":"general","BLANG":"en"}', 'provider' => 'groq'];
            }
        );

        $sorter = new MessageSorter(
            $aiFacade,
            $promptRepository,
            $this->createMock(ModelConfigService::class),
            $this->createMock(PromptService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(DiscordNotificationService::class),
        );

        $sorter->classify(['BTEXT' => 'make the car blue', 'BLANG' => 'en', 'BTOPIC' => ''], $history, null);

        return $sent;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function turnByRole(array $messages, string $role): string
    {
        foreach ($messages as $entry) {
            if ($role === $entry['role']) {
                return (string) $entry['content'];
            }
        }

        return '';
    }
}

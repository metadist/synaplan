<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\SynapseRouter;
use App\Service\Message\TopicAliasResolver;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageClassifierTest extends TestCase
{
    // Intersection types let PHPStan know these properties expose both the
    // collaborator's API and PHPUnit's mock API (`expects()`, `method()`).
    // Without this PHPStan emits `method.notFound` for every `->method()`
    // / `->expects()` call, forcing baseline bumps on every new test case.
    private MessageSorter&MockObject $messageSorter;
    private SynapseRouter&MockObject $synapseRouter;
    private TopicAliasResolver $topicAliasResolver;
    private MessageMetaRepository&MockObject $messageMetaRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private ConfigRepository&MockObject $configRepository;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private MessageClassifier $service;

    protected function setUp(): void
    {
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->synapseRouter = $this->createMock(SynapseRouter::class);
        // Real resolver — it's pure logic with no collaborators, so a stub
        // would add noise without protecting any boundary.
        $this->topicAliasResolver = new TopicAliasResolver();
        $this->messageMetaRepository = $this->createMock(MessageMetaRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Disable Synapse Routing so tests exercise MessageSorter directly
        $this->configRepository->method('getValue')->willReturn('0');

        $this->service = new MessageClassifier(
            $this->messageSorter,
            $this->synapseRouter,
            $this->topicAliasResolver,
            $this->messageMetaRepository,
            $this->modelConfigService,
            $this->configRepository,
            $this->em,
            $this->logger
        );
    }

    public function testClassifyWithPromptOverride(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test message');
        $message->method('getLanguage')->willReturn('en');

        $promptMeta = $this->createMock(MessageMeta::class);
        $promptMeta->method('getMetaValue')->willReturn('tools:pic');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) use ($promptMeta) {
                if ('PROMPTID' === $criteria['metaKey']) {
                    return $promptMeta;
                }

                return null;
            });

        $result = $this->service->classify($message);

        $this->assertEquals('tools:pic', $result['topic']);
        $this->assertEquals('en', $result['language']);
        $this->assertEquals('prompt_override', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    public function testClassifyWithToolCommand(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(2);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('/pic generate a cat');
        $message->method('getLanguage')->willReturn('en');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturn(null);

        $result = $this->service->classify($message);

        $this->assertEquals('tools:pic', $result['topic']);
        $this->assertEquals('tool_command', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    public function testClassifyWithAiSorting(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(3);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Hello, how are you?');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->messageSorter
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => 'CHAT',
                'language' => 'en',
                'sorting_model_id' => 5,
                'sorting_provider' => 'ollama',
                'sorting_model_name' => 'llama3',
            ]);

        $result = $this->service->classify($message);

        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals('en', $result['language']);
        $this->assertEquals('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
        $this->assertEquals(5, $result['model_id']);
    }

    public function testClassifyDetectsVidCommand(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(4);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('/vid create a video');
        $message->method('getLanguage')->willReturn('de');

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->classify($message);

        $this->assertEquals('tools:vid', $result['topic']);
        $this->assertEquals('tool_command', $result['source']);
    }

    public function testClassifyWithModelOverride(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(5);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test');
        $message->method('getLanguage')->willReturn('en');

        $promptMeta = $this->createMock(MessageMeta::class);
        $promptMeta->method('getMetaValue')->willReturn('CHAT');

        $modelMeta = $this->createMock(MessageMeta::class);
        $modelMeta->method('getMetaValue')->willReturn('42');

        $this->messageMetaRepository
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) use ($promptMeta, $modelMeta) {
                if ('PROMPTID' === $criteria['metaKey']) {
                    return $promptMeta;
                } elseif ('MODEL_ID' === $criteria['metaKey']) {
                    return $modelMeta;
                }

                return null;
            });

        $result = $this->service->classify($message);

        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals(42, $result['model_id']);
        $this->assertEquals('prompt_override', $result['source']);
    }

    public function testClassifyLogsClassification(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(6);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Test');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'CHAT',
            'language' => 'en',
        ]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $this->service->classify($message);
    }

    public function testClassifyPassesImagesToSorter(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(7);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Combine these two images');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        // Mock that the message has files (images)
        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileMime')->willReturn('image/png');
        $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
        $message->method('getFiles')->willReturn($files);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        // The sorter should be called, it shouldn't be intercepted
        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'mediamaker',
            'language' => 'en',
        ]);

        $result = $this->service->classify($message);
        $this->assertEquals('mediamaker', $result['topic']);
        $this->assertEquals('ai_sorting', $result['source']);
    }

    public function testDocumentAttachmentForcesAnalyzefileRoute(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(8);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Summarize this');
        $message->method('getLanguage')->willReturn('en');

        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileType')->willReturn('pdf');
        $file->method('getFileName')->willReturn('report.pdf');
        $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
        $message->method('getFiles')->willReturn($files);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->service->classify($message);

        $this->assertSame('analyzefile', $result['topic']);
        $this->assertSame('file_analysis', $result['intent']);
        $this->assertSame('attachment_document_or_audio', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    public function testAudioAttachmentForcesAnalyzefileRoute(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(9);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Transcribe');
        $message->method('getLanguage')->willReturn('de');

        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileType')->willReturn('mp3');
        $file->method('getFileName')->willReturn('voice.mp3');
        $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
        $message->method('getFiles')->willReturn($files);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->service->classify($message);

        $this->assertSame('analyzefile', $result['topic']);
        $this->assertSame('file_analysis', $result['intent']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('synapseEnabledFlagProvider')]
    public function testIsSynapseEnabledParsesVariousValues(?string $configValue, bool $expected): void
    {
        /** @var ConfigRepository&MockObject $configRepo */
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn($configValue);

        $classifier = new MessageClassifier(
            $this->createMock(MessageSorter::class),
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $this->assertSame($expected, $classifier->isSynapseEnabled());
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function synapseEnabledFlagProvider(): iterable
    {
        // BETA: Synapse Routing is OFF by default. Operators must opt-in via
        // the admin UI / system config (`SYNAPSE_ROUTING_ENABLED` = 'true').
        yield 'null defaults to disabled (beta)' => [null, false];
        yield 'string true' => ['true', true];
        yield 'string 1' => ['1', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'string false' => ['false', false];
        yield 'string 0' => ['0', false];
        yield 'string no' => ['no', false];
        yield 'string off' => ['off', false];
        yield 'empty string' => ['', false];
        yield 'random string' => ['banana', false];
    }

    /**
     * Phase 1c: short, plain-chat messages should skip the AI sorter when the
     * fast-path BCONFIG flag is enabled (default-on in production). Builds an
     * isolated classifier (without the global "all configs return '0'" mock)
     * so the default-on behaviour is exercised authentically.
     */
    public function testFastPathClassificationSkipsAiSorter(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Synapse off, fast-path on (the default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }
            if ('CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting) {
                return null; // null → default-on
            }

            return null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(101);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Hello, how are you today?');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $result = $classifier->classify($message);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('chat', $result['intent']);
        $this->assertSame('fast_path_heuristic', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    /**
     * Phase 1c: media verbs ("draw", "create an image of") force the full
     * sorter path so the request can be routed to the right handler instead
     * of the chat handler.
     */
    public function testFastPathYieldsToAiSorterOnMediaVerbs(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'mediamaker', 'language' => 'en']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(102);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Please draw a sunset over a mountain.');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        // Sorter ran → topic comes from the sorter, not the heuristic.
        $this->assertSame('mediamaker', $result['topic']);
    }

    /**
     * Regression for #952.
     *
     * When Synapse Routing is OFF (default), the AI sorter directly emits the
     * granular Synapse-v2 topics from PromptCatalog (`image-generation`,
     * `video-generation`, `audio-generation`). The classifier MUST resolve
     * them to the canonical `mediamaker` topic so `mapTopicToIntent()` can
     * return `image_generation` and the request reaches MediaGenerationHandler
     * instead of falling back to ChatHandler.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function granularMediaTopicProvider(): iterable
    {
        yield 'image-generation → image' => ['image-generation', 'image'];
        yield 'video-generation → video' => ['video-generation', 'video'];
        yield 'audio-generation → audio' => ['audio-generation', 'audio'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('granularMediaTopicProvider')]
    public function testGranularMediaTopicResolvesToMediamakerIntent(string $granularTopic, string $expectedMedia): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(200);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('erstelle ein bild einer katze');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        // AI sorter returns the granular topic without an explicit BMEDIA —
        // exactly the production trace from issue #952.
        $this->messageSorter
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => $granularTopic,
                'language' => 'de',
            ]);

        $result = $this->service->classify($message);

        $this->assertSame('mediamaker', $result['topic'], 'granular topic must be canonicalised before intent mapping');
        $this->assertSame('image_generation', $result['intent'], 'mediamaker → image_generation routes to MediaGenerationHandler');
        $this->assertSame($expectedMedia, $result['media_type'], 'BMEDIA must be inferred from the granular topic when sorter omits it');
        $this->assertSame($granularTopic, $result['granular_topic'], 'granular topic preserved for diagnostics');
    }

    /**
     * The sorter sometimes returns the granular topic AND an explicit BMEDIA
     * (e.g. for richer Synapse-v2 prompts). When both are present, the
     * sorter's BMEDIA wins so the resolver never overwrites a more specific
     * upstream signal.
     */
    public function testGranularTopicDoesNotOverrideExplicitMediaType(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(201);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('mach mir einen song');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'audio-generation',
            'language' => 'de',
            'media_type' => 'audio',
        ]);

        $result = $this->service->classify($message);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('audio', $result['media_type']);
    }

    /**
     * Canonical topics passed through by the sorter (`mediamaker`, `general`,
     * `analyzefile`, ...) must keep working — the resolver is idempotent for
     * non-aliased topics.
     */
    public function testCanonicalTopicPassesThroughResolverUnchanged(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(202);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('zeichne mir eine landschaft');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'mediamaker',
            'language' => 'de',
            'media_type' => 'image',
        ]);

        $result = $this->service->classify($message);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image_generation', $result['intent']);
        $this->assertSame('image', $result['media_type']);
        $this->assertArrayNotHasKey('granular_topic', $result, 'canonical topics never emit a granular_topic field');
    }

    /**
     * Secondary bug from #952: the German imperative "generiere" was missing
     * from the fast-path media-trigger list. With the fast-path enabled
     * (default-on), "generiere ein bild einer katze" would skip the AI
     * sorter entirely and be classified as `general`/chat. Verify the
     * trigger now defers to the full sorter.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function germanMediaImperativeProvider(): iterable
    {
        yield 'generiere (imperative)' => ['generiere ein bild einer katze'];
        yield 'generiert (plural imperative)' => ['generiert eine grafik'];
        yield 'generier (colloquial imperative)' => ['generier mir bitte ein logo'];
    }

    /**
     * Regression coverage for #974 and #1000, rewritten for the
     * Variante B routing policy.
     *
     * BEFORE: the fast-path returned `web_search => false` and tried to
     * compensate by deferring to the AI sorter on a hard-coded
     * `$searchTriggers` blocklist (`kost`, `preis`, `wie teuer`, …).
     * The blocklist was incomplete — any query that didn't happen to
     * match (`günstigsten tankstellen in 10km umgebung von 48161`)
     * silently stayed on the fast-path with `web_search=false`.
     *
     * AFTER: the fast-path no longer decides web_search at all (returns
     * `null`). The actual decision is made downstream by
     * `WebSearchTopicPolicy::shouldSearch()`, which defaults to "search"
     * for any non-media topic without an explicit `tool_internet=false`
     * opt-out. So:
     *
     *   - Fast-path STAYS on the fast-path for these short queries (no
     *     hand-rolled blocklist to maintain).
     *   - `web_search` is `null` in the classification — the policy
     *     fills it in at `MessageProcessor` time using the resolved
     *     prompt's `tool_internet` flag.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function germanCostQueryFastPathProvider(): iterable
    {
        yield 'kostet + travel' => ['Was kostet ein Flug nach Bergen?'];
        yield 'kostet + restaurant' => ['Was kostet ein Kebap-Gericht in Münster?'];
        yield 'wie teuer' => ['Wie teuer ist ein iPhone 17?'];
        yield 'preis (noun)' => ['Was ist der Preis für ein Bitcoin?'];
        yield 'flüge (plural)' => ['Gibt es günstige Flüge im Dezember?'];
        // #1000 case: query that didn't match any of the old triggers.
        yield 'tankstellen (no old trigger)' => ['günstigsten tankstellen in 10km umgebung von 48161'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('germanCostQueryFastPathProvider')]
    public function testFastPathReturnsNullWebSearchHintForGermanCostQueries(string $text): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Synapse off, fast-path on (default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        // The AI sorter must NOT be reached — the fast-path now handles
        // these short queries itself and lets MessageProcessor decide
        // web_search via the project-wide policy.
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(974);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        self::assertSame('fast_path_heuristic', $result['source'], sprintf('Fast-path must take "%s" (no blocklist deferral)', $text));
        self::assertTrue($result['skip_sorting']);
        self::assertNull($result['web_search'], 'Fast-path must NOT pre-empt the WebSearchTopicPolicy decision');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('germanMediaImperativeProvider')]
    public function testFastPathYieldsToAiSorterOnGermanGenerateImperatives(string $text): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Synapse off, fast-path on (default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'image-generation', 'language' => 'de']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(203);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        // End-to-end: granular topic from the sorter is canonicalised and
        // mapped to the media-generation intent.
        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image_generation', $result['intent']);
        $this->assertSame('image', $result['media_type']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * Regression for issue #980.
     *
     * Short German follow-up messages with few distinctive stopwords used
     * to fall through the fast-path heuristic and snap the conversation
     * to English. The classifier now uses the conversation's prior
     * language (from earlier IN-direction messages) as a fallback when
     * the heuristic's signal is weak, so the language stays sticky.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function shortGermanFollowUpProvider(): iterable
    {
        // The exact phrase reported in the issue screenshot.
        yield 'issue #980 phrase' => ['das habe ich dir gegeben'];
        yield 'short acknowledgement' => ['ja genau'];
        yield 'short imperative' => ['zeig mir das'];
        yield 'short reply' => ['okay danke'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shortGermanFollowUpProvider')]
    public function testFastPathInheritsLanguageFromConversationPrior(string $text): void
    {
        $classifier = $this->buildFastPathClassifier();

        // Earlier user turn was clearly German — that's the prior the
        // classifier should fall back to when the current message is
        // too short to score confidently on its own.
        $priorUserMessage = $this->createMock(Message::class);
        $priorUserMessage->method('getDirection')->willReturn('IN');
        $priorUserMessage->method('getLanguage')->willReturn('de');

        $priorAssistantMessage = $this->createMock(Message::class);
        $priorAssistantMessage->method('getDirection')->willReturn('OUT');
        $priorAssistantMessage->method('getLanguage')->willReturn('de');

        $history = [$priorUserMessage, $priorAssistantMessage];

        $message = $this->buildFastPathTextMessage(980, $text);

        $result = $classifier->classify($message, $history);

        // Fast-path still took the request (the message has no media verbs).
        $this->assertSame('fast_path_heuristic', $result['source']);
        $this->assertTrue($result['skip_sorting']);
        // ... but the language now inherits from the conversation prior
        // instead of snapping to 'en'.
        $this->assertSame('de', $result['language'], sprintf(
            'Short German message "%s" must inherit "de" from the conversation history (#980)',
            $text
        ));
    }

    /**
     * The classifier must not blindly copy the prior when the current
     * message clearly switches language (e.g. user replies in English to
     * an earlier German turn). The heuristic's confident hit wins.
     */
    public function testFastPathConfidentHeuristicWinsOverPrior(): void
    {
        $classifier = $this->buildFastPathClassifier();

        $priorUserMessage = $this->createMock(Message::class);
        $priorUserMessage->method('getDirection')->willReturn('IN');
        $priorUserMessage->method('getLanguage')->willReturn('de');

        $history = [$priorUserMessage];

        // Plenty of distinctive English stopwords — easily over threshold.
        $message = $this->buildFastPathTextMessage(981, 'Please tell me what you think and write back soon');

        $result = $classifier->classify($message, $history);

        $this->assertSame('fast_path_heuristic', $result['source']);
        $this->assertSame('en', $result['language'], 'A confident English signal must override the German prior');
    }

    /**
     * Assistant (OUT) messages must NOT contribute to the prior — only
     * the user's own past turns count. Otherwise an early German
     * assistant boilerplate would override a subsequent English user
     * conversation.
     */
    public function testFastPathPriorIgnoresAssistantOutMessages(): void
    {
        $classifier = $this->buildFastPathClassifier();

        $assistantOnly = $this->createMock(Message::class);
        $assistantOnly->method('getDirection')->willReturn('OUT');
        $assistantOnly->method('getLanguage')->willReturn('de');

        $history = [$assistantOnly];

        // Ambiguous short message with no useful heuristic hits.
        $message = $this->buildFastPathTextMessage(982, 'ok');

        $result = $classifier->classify($message, $history);

        // No usable prior (assistant-only history is discarded), no
        // heuristic signal → final fallback to 'en'.
        $this->assertSame('en', $result['language']);
    }

    /**
     * Sentinel BLANG values ('NN' = unclassified default, 'auto' =
     * widget mode) must not contaminate the prior lookup. The classifier
     * should treat them as "unknown" and fall through to the next signal.
     */
    public function testFastPathPriorRejectsSentinelLanguageValues(): void
    {
        $classifier = $this->buildFastPathClassifier();

        $nnMessage = $this->createMock(Message::class);
        $nnMessage->method('getDirection')->willReturn('IN');
        $nnMessage->method('getLanguage')->willReturn('NN');

        $autoMessage = $this->createMock(Message::class);
        $autoMessage->method('getDirection')->willReturn('IN');
        $autoMessage->method('getLanguage')->willReturn('auto');

        $history = [$nnMessage, $autoMessage];

        $message = $this->buildFastPathTextMessage(983, 'ok');

        $result = $classifier->classify($message, $history);

        // Both sentinels rejected → fall back to 'en'.
        $this->assertSame('en', $result['language']);
    }

    /**
     * Build a fast-path-ready classifier (Synapse off, fast-path on)
     * suitable for exercising the prior-language fallback.
     */
    private function buildFastPathClassifier(): MessageClassifier
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        // None of these tests should hit the AI sorter — they all exercise
        // the fast-path's language-inheritance behaviour.
        $sorter->expects($this->never())->method('classify');

        return new MessageClassifier(
            $sorter,
            $this->createMock(SynapseRouter::class),
            new TopicAliasResolver(),
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Build a minimal Message mock that the fast-path will accept (no
     * files, no tool prefix, short enough). Centralises the repeated
     * setup the new test cases share.
     */
    private function buildFastPathTextMessage(int $id, string $text): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260528140000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        return $message;
    }
}

<?php

namespace App\Tests\Unit;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterMatch;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use App\Service\SelfAware\SelfAwareConfig;
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
    private MessageMetaRepository&MockObject $messageMetaRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private ConfigRepository&MockObject $configRepository;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private MessageClassifier $service;

    protected function setUp(): void
    {
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->messageMetaRepository = $this->createMock(MessageMetaRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->configRepository->method('getValue')->willReturn('0');

        $this->service = new MessageClassifier(
            $this->messageSorter,
            $this->messageMetaRepository,
            $this->modelConfigService,
            $this->configRepository,
            $this->em,
            $this->logger,
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($this->configRepository),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
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

    public function testHelpCommandRoutesToSynaplan(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(21);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('/help');
        $message->method('getLanguage')->willReturn('en');

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->classify($message);

        $this->assertSame('synaplan', $result['topic']);
        $this->assertSame('chat', $result['intent']);
        $this->assertSame('tool_command', $result['source']);
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

    /**
     * The sorter input must NOT seed BMULTI. Seeding 0 made a lazy echo of the
     * inbound JSON look like an explicit single-step vote and skipped the
     * planner — the unsafe fallback the vote was designed to avoid (Copilot
     * review of PR #1420).
     */
    public function testSorterInputOmitsBmultiSoAnEchoCannotSkipThePlanner(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(3);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Hello');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getDateTime')->willReturn('20250116120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFileType')->willReturn('');
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $captured = null;
        $this->messageSorter
            ->expects($this->once())
            ->method('classify')
            ->willReturnCallback(function (array $messageData) use (&$captured): array {
                $captured = $messageData;

                return [
                    'topic' => 'general',
                    'language' => 'en',
                    'multi_step' => null,
                    'sorting_model_id' => 5,
                    'sorting_provider' => 'ollama',
                    'sorting_model_name' => 'llama3',
                ];
            });

        $this->service->classify($message);

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('BMULTI', $captured);
    }

    /**
     * Incognito turns run through the classifier as TRANSIENT messages whose
     * id is null (they are never persisted). The prompt/model override lookups
     * key on the message id, so a null id must short-circuit them instead of
     * blowing up with a TypeError (`checkPromptOverride(): Argument #1
     * ($messageId) must be of type int, null given`) — which manifested as a
     * "Connection interrupted" mid-stream in the incognito chat.
     */
    public function testClassifyTransientMessageWithoutIdSkipsOverrideLookups(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(null);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Hi, wie gehts?');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getDateTime')->willReturn('20260707150000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        // A null message id can never have persisted meta — the classifier must
        // not even query the repository for overrides.
        $this->messageMetaRepository->expects($this->never())->method('findOneBy');

        $this->messageSorter
            ->expects($this->once())
            ->method('classify')
            ->willReturn([
                'topic' => 'CHAT',
                'language' => 'de',
                'sorting_model_id' => 5,
                'sorting_provider' => 'ollama',
                'sorting_model_name' => 'llama3',
            ]);

        $result = $this->service->classify($message);

        $this->assertEquals('CHAT', $result['topic']);
        $this->assertEquals('de', $result['language']);
        $this->assertEquals('ai_sorting', $result['source']);
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

    /**
     * Issue #983: a video attachment must take the same analyzefile route
     * as documents and audio (skip the AI sorter, use the ANALYZE model)
     * so the FileAnalysisHandler actually receives the clip.
     */
    public function testVideoAttachmentForcesAnalyzefileRoute(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(11);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('What is in this clip?');
        $message->method('getLanguage')->willReturn('en');

        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileType')->willReturn('mp4');
        $file->method('getFileName')->willReturn('clip.mp4');
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

    /**
     * #1300: a GENERATED audio file is stored with the generic kind
     * BFILETYPE='audio' (not a concrete extension). It must still take the
     * analyzefile route instead of falling through to the AI sorter + RAG
     * (which silently answered from an unrelated knowledge folder).
     */
    public function testGeneratedAudioWithGenericFileTypeForcesAnalyzefileRoute(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1300);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('fasse zusammen');
        $message->method('getLanguage')->willReturn('de');

        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileType')->willReturn('audio'); // generic kind, not 'mp3'
        $file->method('getFileName')->willReturn('tts_123.mp3');
        $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
        $message->method('getFiles')->willReturn($files);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->service->classify($message);

        $this->assertSame('analyzefile', $result['topic']);
        $this->assertSame('file_analysis', $result['intent']);
        $this->assertTrue($result['skip_sorting']);
    }

    /**
     * #1300: same for a generated document with a generic kind and NO usable
     * filename extension — the resolver maps 'document' → representative ext so
     * routing still lands on file_analysis.
     */
    public function testGeneratedDocumentWithGenericKindAndNoExtensionForcesAnalyzefileRoute(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1301);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('was steht drin');
        $message->method('getLanguage')->willReturn('de');

        $file = $this->createMock(\App\Entity\File::class);
        $file->method('getFileType')->willReturn('document');
        $file->method('getFileName')->willReturn('generated-doc'); // no extension
        $files = new \Doctrine\Common\Collections\ArrayCollection([$file]);
        $message->method('getFiles')->willReturn($files);

        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->service->classify($message);

        $this->assertSame('analyzefile', $result['topic']);
        $this->assertSame('file_analysis', $result['intent']);
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
        // Fast-path is default-OFF now, so opt IN explicitly to exercise the
        // heuristic path that this test covers.
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }
            if ('CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting) {
                return '1'; // explicitly enable the fast-path
            }

            return null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
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
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
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
     * The AI sorter emits the canonical `mediamaker` topic plus an explicit
     * BMEDIA. The classifier maps `mediamaker` → `image_generation` so the
     * request reaches MediaGenerationHandler and passes the media type through.
     */
    public function testMediamakerTopicMapsToMediaGenerationIntent(): void
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
            'topic' => 'mediamaker',
            'language' => 'de',
            'media_type' => 'audio',
        ]);

        $result = $this->service->classify($message);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image_generation', $result['intent']);
        $this->assertSame('audio', $result['media_type']);
    }

    /**
     * Canonical topics passed through by the sorter (`mediamaker`, `general`,
     * `analyzefile`, ...) must keep working unchanged.
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
        // Fast-path is default-OFF now, so opt IN explicitly for this test.
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }

            return 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '1' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        // The AI sorter must NOT be reached — the fast-path now handles
        // these short queries itself and lets MessageProcessor decide
        // web_search via the project-wide policy.
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
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

    /**
     * Bug: polite / declarative image requests that carry NO imperative verb
     * (e.g. "hätte ich gerne das bild einer katze") slipped past the
     * fast-path's media-trigger list, were classified as `general`/chat, and
     * the chat model fabricated a broken markdown image instead of routing to
     * the media generator. Same class of bug as #952's German imperative miss.
     *
     * These declarative NOUN-phrase requests across the major UI languages
     * must now defer to the full AI sorter so they reach MediaGenerationHandler.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function declarativeImageRequestProvider(): iterable
    {
        // The exact phrasing from the reported bug.
        yield 'de: hätte ich gerne das bild' => ['hätte ich gerne das bild einer katze', 'de'];
        yield 'de: ein bild von' => ['kannst du mir ein bild von einem hund zeigen', 'de'];
        yield 'de: foto einer' => ['ich möchte das foto einer landschaft', 'de'];
        yield 'en: an image of' => ['i would like an image of a cat', 'en'];
        yield 'en: a picture of' => ['could you give me a picture of a sunset', 'en'];
        yield 'es: una imagen de' => ['quiero una imagen de un gato', 'es'];
        yield 'fr: une image de' => ['je voudrais une image de chat', 'fr'];
        yield 'it: immagine di' => ['vorrei un\'immagine di un gatto', 'it'];
        yield 'tr: resim' => ['bana bir kedi resmi verir misin', 'tr'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declarativeImageRequestProvider')]
    public function testFastPathYieldsToAiSorterOnDeclarativeImageRequests(string $text, string $lang): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Fast-path on (default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'mediamaker', 'language' => $lang, 'media_type' => 'image']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(209);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn($lang);
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        $this->assertSame('mediamaker', $result['topic'], sprintf('"%s" must reach the media generator, not chat', $text));
        $this->assertSame('image_generation', $result['intent']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * MCP data nodes (release 4.0): a fast-pathed message carries
     * source=fast_path_heuristic, which TaskPlanExecutor treats as
     * "single-node, no planning" — the multitask planner (and with it the
     * `mcp_fetch` node) becomes unreachable. Short requests that reference
     * the user's own knowledge base or a connected system must therefore
     * defer to the AI sorter, no matter how short they are.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function dataSourceRequestProvider(): iterable
    {
        // The exact phrasing from the reported gap ("Knowledge Base One" is
        // the user's connected MCP server name).
        yield 'en: knowledge base' => ['please search my knowledge base one for information about the platform', 'en'];
        yield 'en: our crm' => ['look up Acme GmbH in our crm', 'en'];
        yield 'en: my documents' => ['what do my documents say about onboarding', 'en'];
        yield 'de: wissensdatenbank' => ['durchsuche meine wissensdatenbank nach dem onboarding', 'de'];
        yield 'de: meine dokumente' => ['was steht in meine dokumente zum thema urlaub', 'de'];
        yield 'es: base de conocimiento' => ['busca en mi base de conocimiento sobre la empresa', 'es'];
        yield 'tr: bilgi taban' => ['bilgi tabanımda platform hakkında ara', 'tr'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dataSourceRequestProvider')]
    public function testFastPathYieldsToAiSorterOnDataSourceRequests(string $text, string $lang): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }

            // Fast-path is default-OFF; opt IN explicitly so the trigger
            // list (not the flag) is what routes to the sorter here.
            return 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '1' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'general', 'language' => $lang]);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(301);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn($lang);
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        // ai_sorting is exactly the source TaskPlanExecutor requires to run
        // the planner — the precondition for an mcp_fetch node.
        $this->assertSame('ai_sorting', $result['source'], sprintf('"%s" must reach the AI sorter so the planner can consider mcp_fetch', $text));
        $this->assertFalse($result['skip_sorting']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('germanMediaImperativeProvider')]
    public function testFastPathYieldsToAiSorterOnGermanGenerateImperatives(string $text): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Fast-path on (default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
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

        // End-to-end: the sorter's mediamaker topic maps to the
        // media-generation intent.
        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image_generation', $result['intent']);
        $this->assertSame('image', $result['media_type']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * Regression for #1042 review (FExB17).
     *
     * Short document-generation requests must not be shortcut to `general` by
     * the fast-path. When a supported office format/extension is mentioned, the
     * AI sorter has to run so it can route to `officemaker`.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function documentFormatProvider(): iterable
    {
        yield 'docx extension' => ['schreibe es erneut in eine docx datei'];
        yield 'als docx' => ['gib es mir als docx'];
        yield 'excel word' => ['mach eine excel tabelle daraus'];
        yield 'powerpoint' => ['erstelle daraus eine powerpoint'];
        yield 'csv' => ['exportiere das als csv'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentFormatProvider')]
    public function testFastPathYieldsToAiSorterOnDocumentFormats(string $text): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Fast-path on (default).
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'officemaker', 'language' => 'de']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(204);
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

        // Sorter ran → topic comes from the sorter, fast-path was skipped.
        $this->assertSame('officemaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * Regression for #1042 review follow-up.
     *
     * A short edit request without a format keyword ("mach den Titel fett")
     * must NOT be fast-pathed to `general` when the previous assistant turn
     * generated a file — it has to reach the AI sorter so the edit can be
     * routed to `officemaker`.
     */
    public function testFastPathDefersWhenPreviousTurnGeneratedFile(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'officemaker', 'language' => 'de']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $previousFileMessage = $this->createMock(Message::class);
        $previousFileMessage->method('getDirection')->willReturn('OUT');
        $previousFileMessage->method('getText')->willReturn('__FILE_GENERATED__:Zweiter_Weltkrieg.docx');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(205);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Kannst du den Titel bitte fett machen');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message, [$previousFileMessage]);

        $this->assertSame('officemaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * Counterpart: when the previous assistant turn was a normal reply (no
     * generated file), a plain chat message still takes the fast path.
     */
    public function testFastPathTakenWhenPreviousTurnIsNormalReply(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Fast-path is default-OFF now, so opt IN explicitly for this test.
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }

            return 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '1' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $previousReply = $this->createMock(Message::class);
        $previousReply->method('getDirection')->willReturn('OUT');
        $previousReply->method('getText')->willReturn('Sure, here is the answer to your question.');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(206);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Thanks, that helps a lot!');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $result = $classifier->classify($message, [$previousReply]);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('fast_path_heuristic', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    /**
     * Multi-message editing: a normal chat turn is interleaved after the file
     * was generated. A later edit that references the document must still reach
     * the AI sorter (tier b), even though the most recent assistant turn was a
     * plain reply.
     */
    public function testFastPathDefersForLaterDocumentEditAfterInterleavedChat(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'QDRANT_SEARCH' === $group ? '0' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'officemaker', 'language' => 'de']);

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $fileTurn = $this->createMock(Message::class);
        $fileTurn->method('getDirection')->willReturn('OUT');
        $fileTurn->method('getText')->willReturn('__FILE_GENERATED__:Zweiter_Weltkrieg.docx');

        $interleavedReply = $this->createMock(Message::class);
        $interleavedReply->method('getDirection')->willReturn('OUT');
        $interleavedReply->method('getText')->willReturn('Das bedeutet, dass sehr viele Menschen betroffen waren.');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(207);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Kannst du den Titel in der Datei jetzt zentrieren');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260518120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message, [$fileTurn, $interleavedReply]);

        $this->assertSame('officemaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertFalse($result['skip_sorting']);
    }

    /**
     * Counterpart: after a file exists earlier and an interleaved reply, a plain
     * chat message with no document reference still takes the fast path (no
     * over-deferral).
     */
    public function testFastPathTakenForUnrelatedChatAfterEarlierFile(): void
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        // Fast-path is default-OFF now, so opt IN explicitly for this test.
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }

            return 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '1' : null;
        });

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
        );

        $fileTurn = $this->createMock(Message::class);
        $fileTurn->method('getDirection')->willReturn('OUT');
        $fileTurn->method('getText')->willReturn('__FILE_GENERATED__:report.docx');

        $interleavedReply = $this->createMock(Message::class);
        $interleavedReply->method('getDirection')->willReturn('OUT');
        $interleavedReply->method('getText')->willReturn('Gerne, hier ist die Erklärung.');

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(208);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('Super, danke dir vielmals');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $result = $classifier->classify($message, [$fileTurn, $interleavedReply]);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('fast_path_heuristic', $result['source']);
        $this->assertTrue($result['skip_sorting']);
    }

    /**
     * Session files, image half: "mach es blau" right after an image was
     * generated names no format and hits none of the media trigger substrings,
     * so the fast-path used to shortcut it to `general` — where the chat model
     * can only talk about the picture instead of editing it. Generated media
     * carries no `__FILE_GENERATED__:` marker, so it needs its own guard.
     */
    public function testFastPathDefersWhenPreviousTurnGeneratedAnImage(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image', 'input_mode' => 'reference_images']);

        $classifier = $this->classifierWithFastPathEnabled($sorter);

        $imageTurn = $this->createMock(Message::class);
        $imageTurn->method('getDirection')->willReturn('OUT');
        $imageTurn->method('getText')->willReturn('Here is your image');
        $imageTurn->method('getFilePath')->willReturn('ai/car-sunset.png');

        $result = $classifier->classify($this->plainMessage(301, 'mach es blau'), [$imageTurn]);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
        $this->assertSame('reference_images', $result['input_mode']);
    }

    /**
     * Multi-turn editing: normal chat is interleaved after the picture was
     * generated, and a later message references it by a visual property.
     */
    public function testFastPathDefersForLaterImageEditAfterInterleavedChat(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image']);

        $classifier = $this->classifierWithFastPathEnabled($sorter);

        $imageTurn = $this->createMock(Message::class);
        $imageTurn->method('getDirection')->willReturn('OUT');
        $imageTurn->method('getText')->willReturn('Here is your image');
        $imageTurn->method('getFilePath')->willReturn('ai/car-sunset.png');

        $interleavedReply = $this->createMock(Message::class);
        $interleavedReply->method('getDirection')->willReturn('OUT');
        $interleavedReply->method('getText')->willReturn('Gerne, hier ist die Erklaerung.');
        $interleavedReply->method('getFilePath')->willReturn('');

        $result = $classifier->classify(
            $this->plainMessage(302, 'kannst du die Farbe noch anpassen'),
            [$imageTurn, $interleavedReply],
        );

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
    }

    /**
     * Counterpart: no over-deferral. Plain chat after an interleaved reply still
     * takes the fast path even though the thread contains a generated picture.
     */
    public function testFastPathTakenForUnrelatedChatAfterEarlierImage(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithFastPathEnabled($sorter);

        $imageTurn = $this->createMock(Message::class);
        $imageTurn->method('getDirection')->willReturn('OUT');
        $imageTurn->method('getText')->willReturn('Here is your image');
        $imageTurn->method('getFilePath')->willReturn('ai/car-sunset.png');

        $interleavedReply = $this->createMock(Message::class);
        $interleavedReply->method('getDirection')->willReturn('OUT');
        $interleavedReply->method('getText')->willReturn('Gerne, hier ist die Erklaerung.');
        $interleavedReply->method('getFilePath')->willReturn('');

        $result = $classifier->classify(
            $this->plainMessage(303, 'Super, danke dir vielmals'),
            [$imageTurn, $interleavedReply],
        );

        $this->assertSame('general', $result['topic']);
        $this->assertSame('fast_path_heuristic', $result['source']);
    }

    /**
     * A generated DOCUMENT must not trip the media guard — documents have their
     * own (already shipped) deferral and the media probe is extension-based.
     */
    public function testGeneratedDocumentDoesNotTripTheMediaGuard(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithFastPathEnabled($sorter);

        $documentTurn = $this->createMock(Message::class);
        $documentTurn->method('getDirection')->willReturn('OUT');
        $documentTurn->method('getText')->willReturn('Done.');
        $documentTurn->method('getFilePath')->willReturn('ai/report.docx');

        $result = $classifier->classify($this->plainMessage(304, 'Super, danke dir vielmals'), [$documentTurn]);

        $this->assertSame('fast_path_heuristic', $result['source']);
    }

    /**
     * BINPUTMODE was parsed by the sorter and then dropped here, so the media
     * handler never learned that a request edits an existing picture.
     */
    public function testInputModeFromTheSorterReachesTheClassification(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'mediamaker',
            'language' => 'en',
            'media_type' => 'image',
            'input_mode' => 'reference_images',
        ]);

        $result = $this->service->classify($this->plainMessage(305, 'make the car blue'));

        $this->assertSame('reference_images', $result['input_mode']);
    }

    public function testClassificationHasNoInputModeWhenTheSorterOmitsIt(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
        ]);

        $this->assertArrayNotHasKey('input_mode', $this->service->classify($this->plainMessage(306, 'hello there')));
    }

    // ──────────────────────────────────────────────
    //  Phase 8: embedding-router cascade layer
    // ──────────────────────────────────────────────

    public function testEmbeddingRouterDisabledByDefaultNeverConsultsTheRouter(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->expects($this->never())->method('findClosestAnchor');

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn(['topic' => 'general', 'language' => 'en']);

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: false);

        $result = $classifier->classify($this->plainMessage(400, 'Hello, how are you?'));

        $this->assertSame('ai_sorting', $result['source']);
    }

    public function testConfidentEmbeddingMatchSkipsTheAiSorter(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->method('findClosestAnchor')->willReturn(new EmbeddingRouterMatch('mediamaker', 0.95, [['topic' => 'general', 'score' => 0.4]]));

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: true, threshold: 0.88);

        // Deliberately gibberish text: no local-language heuristic anchor
        // fires for any of the five supported languages, so
        // resolveConfidentLanguage() falls back to the 'de' already pinned
        // on the message by plainMessage() rather than guessing.
        $result = $classifier->classify($this->plainMessage(401, 'zzzqx foobar wibble 12345'));

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertSame('embedding_router', $result['source']);
        $this->assertTrue($result['skip_sorting']);
        $this->assertSame('image_generation', $result['intent']);
        $this->assertNull($result['web_search']);
        $this->assertSame(0.95, $result['routing_confidence']);
        // RoutingDecision::$discardedAlternatives is list<string> (see its
        // docblock) — EmbeddingRouterMatch's structured {topic,score} pairs
        // are formatted down to that shape here, matching every other layer.
        $this->assertSame(['general (0.400)'], $result['routing_discarded_alternatives']);
    }

    public function testSubThresholdEmbeddingMatchEscalatesToTheAiSorter(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        // Below the configured 0.88 threshold.
        $embeddingRouter->method('findClosestAnchor')->willReturn(new EmbeddingRouterMatch('mediamaker', 0.5));

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn(['topic' => 'general', 'language' => 'en']);

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: true, threshold: 0.88);

        $result = $classifier->classify($this->plainMessage(402, 'Something ambiguous'));

        $this->assertSame('ai_sorting', $result['source']);
    }

    public function testNoEmbeddingMatchEscalatesToTheAiSorter(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->method('findClosestAnchor')->willReturn(null);

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn(['topic' => 'general', 'language' => 'en']);

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: true);

        $result = $classifier->classify($this->plainMessage(403, 'Something with no anchors'));

        $this->assertSame('ai_sorting', $result['source']);
    }

    /**
     * A confident topic match with an undetectable language must still defer
     * to the AI sorter rather than guess — same guard as the fast-path (a
     * German "wer bist du?" was once answered in English because an
     * undetectable language silently defaulted to 'en').
     */
    public function testConfidentEmbeddingMatchWithoutConfidentLanguageEscalatesToTheAiSorter(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->method('findClosestAnchor')->willReturn(new EmbeddingRouterMatch('general', 0.99));

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn(['topic' => 'general', 'language' => 'en']);

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: true);

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(404);
        $message->method('getUserId')->willReturn(10);
        // No German/English/etc. anchor words, and 'NN' (unknown) on the message.
        $message->method('getText')->willReturn('xyzzy plugh');
        $message->method('getLanguage')->willReturn('NN');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260827120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        $this->assertSame('ai_sorting', $result['source']);
    }

    /**
     * With both new layers on, Phase 8's refusal must reach the AI sorter and
     * not be intercepted by Phase 9. The refusal is specifically a request for
     * BLANG resolution, and Phase 9 answers `language: 'en'` — i.e. exactly
     * the guess Phase 8 declined to make.
     */
    public function testAnEmbeddingMatchDeclinedForLanguageReachesTheSorterEvenWithPhase9On(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->method('findClosestAnchor')->willReturn(new EmbeddingRouterMatch('general', 0.99));

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn(['topic' => 'general', 'language' => 'de']);

        $classifier = $this->classifierWithBothNewLayers($embeddingRouter, $sorter);

        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(405);
        $message->method('getUserId')->willReturn(10);
        // No language anchor words, and 'NN' (unknown) on the message.
        $message->method('getText')->willReturn('xyzzy plugh');
        $message->method('getLanguage')->willReturn('NN');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260827120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        $result = $classifier->classify($message);

        $this->assertSame('ai_sorting', $result['source']);
        $this->assertArrayNotHasKey('defer_routing_to_chat', $result);
        $this->assertSame('de', $result['language']);
    }

    /**
     * The complement of the test above: when Phase 8 finds NO match at all it
     * has expressed no opinion, so Phase 9 is free to take the turn.
     */
    public function testPhase9StillTakesTheTurnWhenTheEmbeddingRouterFoundNothing(): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);
        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->method('findClosestAnchor')->willReturn(null);

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithBothNewLayers($embeddingRouter, $sorter);

        $result = $classifier->classify($this->plainMessage(406, 'What is the capital of France?'));

        $this->assertTrue($result['defer_routing_to_chat']);
    }

    public function testNativeToolRoutingDefersTheDecisionToTheAnsweringCall(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: true);

        $result = $classifier->classify($this->plainMessage(900, 'What is the capital of France?'));

        // The acceptance criterion of Phase 9: a simple chat turn costs no
        // sorter call at all.
        self::assertSame('general', $result['topic']);
        self::assertSame('chat', $result['intent']);
        self::assertSame('native_tool_calling', $result['source']);
        self::assertTrue($result['skip_sorting']);
        self::assertTrue($result['defer_routing_to_chat']);
        // No sorter means no BWEBSEARCH vote, exactly as on the fast-path.
        self::assertNull($result['web_search']);
    }

    public function testDisabledNativeToolRoutingKeepsTheSorterCall(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: false);

        $result = $classifier->classify($this->plainMessage(901, 'What is the capital of France?'));

        self::assertSame('ai_sorting', $result['source']);
        self::assertArrayNotHasKey('defer_routing_to_chat', $result);
    }

    /**
     * The pre-gate: on an account whose chat provider has no native tool
     * calling, deferring would buy a guaranteed re-route on every single
     * message.
     */
    public function testNoDeferralWhenTheAccountChatProviderCannotDoToolCalling(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: true, chatProvider: 'ollama');

        $result = $classifier->classify($this->plainMessage(902, 'What is the capital of France?'));

        self::assertSame('ai_sorting', $result['source']);
        self::assertArrayNotHasKey('defer_routing_to_chat', $result);
    }

    /**
     * The second pass after an unhonourable deferral: without this the turn
     * would defer, come back, and defer again forever.
     */
    public function testASecondPassWithDeferralDisallowedGoesToTheSorter(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'source' => 'ai_sorting',
        ]);

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: true);

        $result = $classifier->classify(
            $this->plainMessage(903, 'What is the capital of France?'),
            [],
            null,
            allowRoutingDeferral: false,
        );

        self::assertSame('ai_sorting', $result['source']);
        self::assertArrayNotHasKey('defer_routing_to_chat', $result);
    }

    /**
     * Deferring means "let the answering model decide", but an attachment
     * rule has already decided — the deferral must sit BELOW every
     * deterministic layer, not above them.
     */
    public function testDeterministicLayersStillWinOverTheDeferral(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: true);

        $result = $classifier->classify($this->plainMessage(904, '/pic a cat on a bike'));

        self::assertSame('tool_command', $result['source']);
        self::assertArrayNotHasKey('defer_routing_to_chat', $result);
    }

    /**
     * Self-awareness answers by routing to the `synaplan` topic, which only
     * the AI sorter can pick — the hand-off toolset covers the four system
     * capabilities and nothing else. Deferring such a question would answer it
     * from a plain chat turn that knows nothing about the product.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfAwareGuardUtterances')]
    public function testTheDeferralStepsAsideForSelfAwareMetaQuestions(string $text): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'synaplan', 'language' => 'en']);

        $classifier = $this->classifierWithNativeToolRouting($sorter, enabled: true);

        $result = $classifier->classify($this->plainMessage(905, $text));

        self::assertSame('synaplan', $result['topic']);
        self::assertSame('ai_sorting', $result['source']);
        self::assertArrayNotHasKey('defer_routing_to_chat', $result);
    }

    /**
     * Same rule for the Phase 8 layer: a confident anchor match can only ever
     * be one of the four system topics, so `synaplan` would be unreachable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfAwareGuardUtterances')]
    public function testTheEmbeddingRouterStepsAsideForSelfAwareMetaQuestions(string $text): void
    {
        $this->messageMetaRepository->method('findOneBy')->willReturn(null);

        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        $embeddingRouter->expects($this->never())->method('findClosestAnchor');

        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'synaplan', 'language' => 'en']);

        $classifier = $this->classifierWithEmbeddingRouter($embeddingRouter, $sorter, enabled: true);

        $result = $classifier->classify($this->plainMessage(906, $text));

        self::assertSame('synaplan', $result['topic']);
        self::assertSame('ai_sorting', $result['source']);
    }

    /**
     * @param string $chatProvider the account's default chat provider, which decides
     *                             whether the classifier's cheap pre-gate lets the
     *                             deferral through at all
     */
    private function classifierWithNativeToolRouting(
        MessageSorter $sorter,
        bool $enabled,
        string $chatProvider = 'anthropic',
    ): MessageClassifier {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'NATIVE_TOOL_ROUTING' === $group && 'ENABLED' === $setting
                ? ($enabled ? '1' : '0')
                : null
        );

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultProvider')->willReturn($chatProvider);

        return new MessageClassifier(
            $sorter,
            $this->messageMetaRepository,
            $modelConfig,
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            new NativeToolRoutingConfig($configRepo),
            new ToolCallingCapability(),
            new SelfAwareConfig($configRepo),
        );
    }

    /**
     * Both new cascade layers on at once — the configuration in which the
     * layers can shadow each other.
     */
    private function classifierWithBothNewLayers(
        EmbeddingRouterService $embeddingRouter,
        MessageSorter $sorter,
        float $threshold = 0.88,
    ): MessageClassifier {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($threshold): ?string {
                if ('EMBEDDING_ROUTER' === $group && 'ENABLED' === $setting) {
                    return '1';
                }
                if ('EMBEDDING_ROUTER' === $group && 'CONFIDENCE_THRESHOLD' === $setting) {
                    return (string) $threshold;
                }
                if ('NATIVE_TOOL_ROUTING' === $group && 'ENABLED' === $setting) {
                    return '1';
                }

                return null;
            }
        );

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultProvider')->willReturn('anthropic');

        return new MessageClassifier(
            $sorter,
            $this->messageMetaRepository,
            $modelConfig,
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $embeddingRouter,
            new EmbeddingRouterConfig($configRepo),
            new NativeToolRoutingConfig($configRepo),
            new ToolCallingCapability(),
            new SelfAwareConfig($configRepo),
        );
    }

    /**
     * The Phase 9 deferral switched off, which is the default everywhere and
     * therefore the right baseline for every test that is not about it.
     */
    private function disabledNativeToolRouting(): NativeToolRoutingConfig
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn(null);

        return new NativeToolRoutingConfig($configRepo);
    }

    private function classifierWithEmbeddingRouter(
        EmbeddingRouterService $embeddingRouter,
        MessageSorter $sorter,
        bool $enabled,
        float $threshold = 0.88,
    ): MessageClassifier {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting) use ($enabled, $threshold): ?string {
            if ('EMBEDDING_ROUTER' === $group && 'ENABLED' === $setting) {
                return $enabled ? '1' : '0';
            }
            if ('EMBEDDING_ROUTER' === $group && 'CONFIDENCE_THRESHOLD' === $setting) {
                return (string) $threshold;
            }

            return null;
        });

        return new MessageClassifier(
            $sorter,
            $this->messageMetaRepository,
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $embeddingRouter,
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
            new SelfAwareConfig($configRepo),
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function selfAwareGuardUtterances(): array
    {
        return [
            ['can you make PDFs?'],
            ['was kannst du?'],
            ['¿puedes buscar en internet?'],
            ['peux-tu lire mes e-mails ?'],
            ['video yapabilir misin?'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('selfAwareGuardUtterances')]
    public function testFastPathDefersSelfAwareMetaQuestions(string $text): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'synaplan', 'language' => 'en']);

        $classifier = $this->classifierWithFastPathEnabled($sorter);
        $result = $classifier->classify($this->plainMessage(410, $text));

        $this->assertSame('synaplan', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
    }

    public function testFastPathKeepsPdfRequestsOnGeneralWhenEngineOff(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithFastPathEnabled($sorter, false);
        $result = $classifier->classify($this->plainMessage(412, 'gib es mir als pdf'));

        $this->assertSame('general', $result['topic']);
        $this->assertSame('fast_path_heuristic', $result['source']);
    }

    public function testFastPathDefersPdfRequestsWhenEngineOn(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())
            ->method('classify')
            ->willReturn(['topic' => 'officemaker', 'language' => 'de']);

        $classifier = $this->classifierWithFastPathEnabled($sorter, true);
        $result = $classifier->classify($this->plainMessage(413, 'gib es mir als pdf'));

        $this->assertSame('officemaker', $result['topic']);
        $this->assertSame('ai_sorting', $result['source']);
    }

    public function testFastPathStillClassifiesOrdinaryChat(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');

        $classifier = $this->classifierWithFastPathEnabled($sorter);
        $result = $classifier->classify($this->plainMessage(411, 'write me a poem'));

        $this->assertSame('general', $result['topic']);
        $this->assertSame('fast_path_heuristic', $result['source']);
    }

    private function classifierWithFastPathEnabled(MessageSorter $sorter, bool $officeEngineOn = false): MessageClassifier
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            if ('QDRANT_SEARCH' === $group) {
                return '0';
            }

            return 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '1' : null;
        });

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn($officeEngineOn);

        return new MessageClassifier(
            $sorter,
            $this->createMock(MessageMetaRepository::class),
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($configRepo),
            $this->disabledNativeToolRouting(),
            new ToolCallingCapability(),
            new SelfAwareConfig($configRepo),
            $converter,
        );
    }

    private function plainMessage(int $id, string $text): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('de');
        $message->method('getFile')->willReturn(0);
        $message->method('getFiles')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $message->method('getDateTime')->willReturn('20260827120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn('');
        $message->method('getFileText')->willReturn('');

        return $message;
    }
}

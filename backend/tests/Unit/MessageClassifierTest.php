<?php

namespace App\Tests\Unit;

use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ThumbnailService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\MediaPromptExtractor;
use App\Service\Message\PerfPipelineFlag;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Tests\Support\MediaGenerationHandlerFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A sorter-routed mediamaker/audio turn whose extracted "script" is just the
 * user's own request must never be synthesized (the MP3 would read the
 * question back). The handler hands the turn to the general chat answer.
 */
final class MediaGenerationHandlerAudioEchoTest extends TestCase
{
    private const KINDERLIED_REQUEST = 'Bring mir mit einem Kinderlied die persischen Zahlen 0 bis 10 bei.';

    private AiFacade&MockObject $aiFacade;
    private MediaPromptExtractor&MockObject $promptExtractor;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;
    private GeneratedFileRegistrar&MockObject $generatedFileRegistrar;
    private ChatHandler&MockObject $chatHandler;
    private MessageBusInterface&MockObject $messageBus;
    private PerfPipelineFlag&MockObject $perfPipelineFlag;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->generatedFileRegistrar = $this->createMock(GeneratedFileRegistrar::class);
        $this->chatHandler = $this->createMock(ChatHandler::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        // Memory extraction fully enabled so the dispatch (or its absence) is observable.
        $this->perfPipelineFlag = $this->createMock(PerfPipelineFlag::class);
        $this->perfPipelineFlag->method('isEnabled')->willReturn(true);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->with('TEXT2SOUND', 7)->willReturn(42);
        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->bootstrapAudioModel();
    }

    public function testEchoedScriptIsAnsweredAsChatInsteadOfSynthesized(): void
    {
        $handler = $this->handler($this->chatHandler);
        $message = $this->messageStub(self::KINDERLIED_REQUEST);

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => self::KINDERLIED_REQUEST,
            'media_type' => 'audio',
        ]);

        $this->aiFacade->expects(self::never())->method('synthesize');
        $this->generatedFileRegistrar->expects(self::never())->method('register');
        // ChatHandler queues its own extraction for this turn; the media path
        // must not queue a second ExtractMemoriesCommand for the same message.
        $this->messageBus->expects(self::never())->method('dispatch');

        $seenClassification = null;
        $seenOptions = null;
        $this->chatHandler->expects(self::once())
            ->method('handleStream')
            ->willReturnCallback(static function (
                Message $message,
                array $thread,
                array $classification,
                callable $streamCallback,
                ?callable $progressCallback,
                array $options,
            ) use (&$seenClassification, &$seenOptions): array {
                $seenClassification = $classification;
                $seenOptions = $options;
                $streamCallback('Sefr, yek, do, se …');

                return ['content' => 'Sefr, yek, do, se …', 'metadata' => ['model' => 'chat-model']];
            });

        $result = $handler->handle(
            $message,
            [],
            ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio', 'intent' => 'image_generation', 'model_id' => 99, 'prompt_metadata' => ['aiModel' => 0]],
            null,
            ['resolved_prompt_data' => ['prompt' => 'mediamaker prompt bundle'], 'channel' => 'web'],
        );

        self::assertSame('Sefr, yek, do, se …', $result['content']);
        self::assertSame('mediamaker:audio', $result['metadata']['rerouted_from'] ?? null);
        self::assertSame('chat-model', $result['metadata']['model'] ?? null);
        self::assertArrayNotHasKey('media_type', $result['metadata'], 'nothing was generated, so nothing may look billable');

        // The persistence layer must learn that this turn became a chat answer.
        $effective = $result['metadata'][MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY] ?? null;
        self::assertIsArray($effective);
        self::assertSame('general', $effective['topic']);
        self::assertSame('chat', $effective['intent']);
        self::assertArrayHasKey('media_type', $effective);
        self::assertNull($effective['media_type']);

        self::assertIsArray($seenClassification);
        self::assertSame('general', $seenClassification['topic']);
        self::assertSame('chat', $seenClassification['intent']);
        self::assertSame('de', $seenClassification['language']);
        self::assertArrayNotHasKey('media_type', $seenClassification);
        self::assertArrayNotHasKey('model_id', $seenClassification);
        self::assertArrayNotHasKey('prompt_metadata', $seenClassification);

        self::assertIsArray($seenOptions);
        self::assertArrayNotHasKey('resolved_prompt_data', $seenOptions, 'the mediamaker prompt bundle must not leak into the chat answer');
        self::assertSame('web', $seenOptions['channel'] ?? null);
    }

    public function testExtractedPayloadIsStillSynthesized(): void
    {
        $handler = $this->handler($this->chatHandler);
        $message = $this->messageStub('Lies mir bitte folgenden Text als Sprachnachricht vor: Guten Morgen zusammen.');

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'Guten Morgen zusammen.',
            'media_type' => 'audio',
        ]);

        $this->chatHandler->expects(self::never())->method('handleStream');
        $this->aiFacade->expects(self::once())
            ->method('synthesize')
            ->with('Guten Morgen zusammen.', 'de', 7, self::anything())
            ->willReturn(['relativePath' => '7/tts.mp3', 'provider' => 'openai', 'model' => 'tts-1', 'text_length' => 22]);
        // The media path still extracts memories from the user turn — exactly once.
        $this->messageBus->expects(self::once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $result = $handler->handle($message, [], ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']);

        self::assertSame('__AUDIO_GENERATED__', $result['content']);
        self::assertSame('audio', $result['metadata']['media_type'] ?? null);
        self::assertArrayNotHasKey('rerouted_from', $result['metadata']);
        self::assertArrayNotHasKey(MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY, $result['metadata']);
    }

    /**
     * Live voice can hand over a message that was never stored (the transcript
     * row failed) without being incognito. The memory job cannot reference it;
     * that must cost the memory, never the generated audio.
     */
    public function testAMessageThatWasNeverStoredStillGetsItsAudio(): void
    {
        $handler = $this->handler($this->chatHandler);
        $message = $this->messageStub('Lies mir bitte folgenden Text als Sprachnachricht vor: Guten Morgen zusammen.', null);

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'Guten Morgen zusammen.',
            'media_type' => 'audio',
        ]);
        $this->aiFacade->expects(self::once())
            ->method('synthesize')
            ->willReturn(['relativePath' => '7/tts.mp3', 'provider' => 'openai', 'model' => 'tts-1', 'text_length' => 22]);
        $this->messageBus->expects(self::never())->method('dispatch');

        $result = $handler->handle($message, [], ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']);

        self::assertSame('__AUDIO_GENERATED__', $result['content']);
        self::assertSame('audio', $result['metadata']['media_type'] ?? null);
    }

    public function testSlashTtsCommandSpeaksExactlyWhatWasTyped(): void
    {
        $handler = $this->handler($this->chatHandler);
        $message = $this->messageStub('/tts '.self::KINDERLIED_REQUEST);

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => self::KINDERLIED_REQUEST,
            'media_type' => 'audio',
        ]);

        $this->chatHandler->expects(self::never())->method('handleStream');
        $this->aiFacade->expects(self::once())
            ->method('synthesize')
            ->with(self::KINDERLIED_REQUEST, 'de', 7, self::anything())
            ->willReturn(['relativePath' => '7/tts.mp3', 'provider' => 'openai', 'model' => 'tts-1', 'text_length' => 66]);

        $result = $handler->handle($message, [], ['topic' => 'tools:tts', 'language' => 'de']);

        self::assertSame('__AUDIO_GENERATED__', $result['content']);
    }

    public function testWithoutChatHandlerTheUserGetsAnActionableHintInsteadOfTheirOwnVoiceNote(): void
    {
        $handler = $this->handler(null);
        $message = $this->messageStub(self::KINDERLIED_REQUEST);

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => self::KINDERLIED_REQUEST,
            'media_type' => 'audio',
        ]);

        $this->aiFacade->expects(self::never())->method('synthesize');

        $result = $handler->handle($message, [], ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']);

        self::assertSame('tts_script_echoes_request', $result['metadata']['error'] ?? null);
        self::assertArrayNotHasKey('media_type', $result['metadata'], 'a failed turn must not be billed as generated audio');
        self::assertStringContainsString('Lies vor', $result['content']);
    }

    private function handler(?ChatHandler $chatHandler): MediaGenerationHandler
    {
        $mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $mediaJobConfig->method('isAsyncJobsEnabled')->willReturn(false);

        return MediaGenerationHandlerFactory::build([
            'ai' => $this->aiFacade,
            'models' => $this->modelConfigService,
            'em' => $this->em,
            'extractor' => $this->promptExtractor,
            'downloader' => new MediaDownloader(),
            'thumbnails' => $this->createMock(ThumbnailService::class),
            'limits' => $this->rateLimitService,
            'memory' => new PendingMemoryExtraction(
                new MemoryExtractionDispatcher($this->messageBus, new NullLogger()),
                $this->perfPipelineFlag,
                new NullLogger(),
            ),
            'cancellation' => $this->createMock(MediaCancellationStore::class),
            'jobConfig' => $mediaJobConfig,
            'jobs' => $this->createMock(MediaJobService::class),
            'jobDispatcher' => $this->createMock(MediaJobDispatcher::class),
            'jobSync' => $this->createMock(MediaJobMessageSync::class),
            'registrar' => $this->generatedFileRegistrar,
            'gate' => new PremiumFeatureGate(new BillingService('', '')),
            'catalog' => $this->createMock(ConversationFileCatalog::class),
            'uploadDir' => sys_get_temp_dir(),
            'publicBaseUrl' => 'https://app.example.test',
            'folders' => null,
            'chat' => $chatHandler,
        ]);
    }

    private function bootstrapAudioModel(): void
    {
        $user = $this->createMock(User::class);
        $user->method('isMemoriesEnabled')->willReturn(true);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('tts-1');
        $model->method('getName')->willReturn('TTS 1');
        $model->method('getTag')->willReturn('text2sound');
        $model->method('getJson')->willReturn([]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    private function messageStub(string $text, ?int $id = 1001): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('de');
        $message->method('getId')->willReturn($id);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $message;
    }
}

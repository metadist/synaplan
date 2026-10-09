<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\BillingService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A task prompt's pinned aiModel (and an "Again" pick) is read ahead of the
 * DEFAULTMODEL chain. When that row is retired, the handler must route to the
 * replacement the retirement records instead of calling a model the provider
 * has shut down (#2413).
 */
final class MediaGenerationHandlerRetiredModelTest extends TestCase
{
    private const RETIRED_BID = 50;
    private const SUCCESSOR_BID = 42;

    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $models = [
            self::RETIRED_BID => $this->ttsModel('tts-retired', true),
            self::SUCCESSOR_BID => $this->ttsModel('tts-successor', false),
        ];
        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturnCallback(static fn (int $id): ?Model => $models[$id] ?? null);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    public function testAPinnedRetiredModelIsReplacedBeforeTheProviderCall(): void
    {
        $this->modelConfigService->expects(self::once())
            ->method('replacementForRetiredModel')
            ->with(self::RETIRED_BID, 'TEXT2SOUND', 7)
            ->willReturn(self::SUCCESSOR_BID);

        $this->aiFacade->expects(self::once())
            ->method('synthesize')
            ->with('Guten Morgen zusammen.', 'de', 7, self::callback(
                static fn (array $options): bool => 'tts-successor' === $options['model'],
            ))
            ->willReturn(['relativePath' => '7/tts.mp3', 'provider' => 'openai', 'model' => 'tts-successor', 'text_length' => 22]);

        $result = $this->handle();

        self::assertSame('__AUDIO_GENERATED__', $result['content']);
    }

    public function testAPinnedRetiredModelWithoutReplacementIsNeverSentToTheProvider(): void
    {
        $this->modelConfigService->method('replacementForRetiredModel')->willReturn(null);
        $this->aiFacade->expects(self::never())->method('synthesize');

        $result = $this->handle();

        self::assertSame('no_media_model_configured', $result['metadata']['error'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(): array
    {
        $promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $promptExtractor->method('extract')->willReturn([
            'prompt' => 'Guten Morgen zusammen.',
            'media_type' => 'audio',
        ]);

        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $mediaJobConfig->method('isAsyncJobsEnabled')->willReturn(false);

        $handler = new MediaGenerationHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->em,
            new NullLogger(),
            $promptExtractor,
            new UserUploadPathBuilder(),
            $this->createMock(ThumbnailService::class),
            $rateLimitService,
            new MediaErrorMessageBuilder(),
            $messageBus,
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(MediaCancellationStore::class),
            $mediaJobConfig,
            $this->createMock(MediaJobService::class),
            $this->createMock(MediaJobDispatcher::class),
            $this->createMock(MediaJobMessageSync::class),
            $this->createMock(GeneratedFileRegistrar::class),
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createMock(ConversationFileCatalog::class),
            sys_get_temp_dir(),
            'https://app.example.test',
        );

        return $handler->handle(
            $this->messageStub('Lies mir bitte folgenden Text als Sprachnachricht vor: Guten Morgen zusammen.'),
            [],
            ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio', 'prompt_metadata' => ['aiModel' => self::RETIRED_BID]],
        );
    }

    private function ttsModel(string $providerId, bool $retired): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($providerId);
        $model->method('getTag')->willReturn('text2sound');
        $model->method('getJson')->willReturn([]);
        $model->method('isRetired')->willReturn($retired);

        return $model;
    }

    private function messageStub(string $text): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('de');
        $message->method('getId')->willReturn(1001);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $message;
    }
}

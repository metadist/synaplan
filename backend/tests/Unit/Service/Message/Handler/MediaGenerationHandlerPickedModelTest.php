<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Agent\AgentConfig;
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
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\Message\MessageClassifier;
use App\Service\Message\PerfPipelineFlag;
use App\Service\Message\Processing\TurnClassificationResolver;
use App\Service\Message\Processing\TurnStatus;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;
use App\Tests\Support\MediaGenerationHandlerFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An Again of a `/pic` message, from the routing to the provider call: the
 * image is generated with the model the user picked when it makes images,
 * and with the image default when the picked model is a chat model.
 */
final class MediaGenerationHandlerPickedModelTest extends TestCase
{
    private const CHAT_MODEL = 42;
    private const IMAGE_DEFAULT = 77;
    private const PICKED_IMAGE_MODEL = 88;
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private string $uploadDir = '';
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService $modelConfig;
    private MediaGenerationHandler $handler;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-picked-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);

        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfig = $this->modelConfig();

        $prompts = $this->createStub(MediaPromptExtractor::class);
        $prompts->method('extract')->willReturn(['prompt' => 'a red fox', 'media_type' => 'image']);
        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn(['allowed' => true]);
        $jobs = $this->createStub(MediaJobConfig::class);
        $jobs->method('isAsyncJobsEnabled')->willReturn(false);
        $jobs->method('maxActiveJobsPerUser')->willReturn(16);
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn(55);
        $files = $this->createStub(GeneratedFileRegistrar::class);
        $files->method('register')->willReturn($file);

        $this->handler = MediaGenerationHandlerFactory::build([
            'ai' => $this->aiFacade,
            'models' => $this->modelConfig,
            'em' => $this->entityManager(),
            'extractor' => $prompts,
            'downloader' => new MediaDownloader(\Symfony\Component\HttpClient\HttpClient::create()),
            'thumbnails' => $this->createStub(ThumbnailService::class),
            'limits' => $rateLimits,
            'memory' => new PendingMemoryExtraction(
                new MemoryExtractionDispatcher($this->createStub(MessageBusInterface::class), new NullLogger()),
                $this->createStub(PerfPipelineFlag::class),
                new NullLogger(),
            ),
            'cancellation' => $this->createStub(MediaCancellationStore::class),
            'jobConfig' => $jobs,
            'jobs' => $this->createStub(MediaJobService::class),
            'jobDispatcher' => $this->createStub(MediaJobDispatcher::class),
            'jobSync' => $this->createStub(MediaJobMessageSync::class),
            'registrar' => $files,
            'gate' => new PremiumFeatureGate(new BillingService('', '')),
            'catalog' => $this->createStub(ConversationFileCatalog::class),
            'uploadDir' => $this->uploadDir,
            'publicBaseUrl' => 'https://app.example.test',
            'folders' => null,
            'chat' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->uploadDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->uploadDir);
    }

    public function testAChatModelAgainGeneratesWithTheImageDefault(): void
    {
        self::assertSame(['openai', 'gpt-image-1'], $this->generatedWith(self::CHAT_MODEL));
    }

    public function testAnImageModelAgainGeneratesWithThePickedModel(): void
    {
        self::assertSame(['google', 'imagen-4'], $this->generatedWith(self::PICKED_IMAGE_MODEL));
    }

    /**
     * @return array{0: mixed, 1: mixed} the provider and model the image was generated with
     */
    private function generatedWith(int $pickedModelId): array
    {
        $message = $this->message();
        $classification = $this->resolver()->againForBufferedTurn($message, ['is_again' => true, 'model_id' => $pickedModelId], new TurnStatus(null, new NullLogger()));

        $generatedWith = [null, null];
        $this->aiFacade->expects(self::once())->method('generateImage')->willReturnCallback(
            static function (string $prompt, int $userId, array $options) use (&$generatedWith): array {
                $generatedWith = [$options['provider'] ?? null, $options['model'] ?? null];

                return ['images' => [['url' => 'data:image/png;base64,'.self::PNG]], 'provider' => 'test', 'model' => 'test-image', 'image_count' => 1];
            }
        );
        $this->handler->handle($message, [], $classification);

        return $generatedWith;
    }

    private function resolver(): TurnClassificationResolver
    {
        return new TurnClassificationResolver(
            $this->createStub(MessageClassifier::class),
            $this->modelConfig,
            $this->createStub(AgentConfig::class),
            $this->createStub(PromptService::class),
            new TopicIntentRule(new SystemCapabilityRegistry()),
            new NullLogger(),
        );
    }

    private function modelConfig(): ModelConfigService
    {
        $tags = [self::CHAT_MODEL => 'chat', self::IMAGE_DEFAULT => 'text2pic', self::PICKED_IMAGE_MODEL => 'text2pic'];

        $config = $this->createStub(ModelConfigService::class);
        $config->method('getModelTag')->willReturnCallback(static fn (int $modelId): ?string => $tags[$modelId] ?? null);
        $config->method('getDefaultModel')->willReturnCallback(static fn (string $capability): ?int => 'TEXT2PIC' === $capability ? self::IMAGE_DEFAULT : null);
        $config->method('getEffectiveUserIdForMessage')->willReturn(7);

        return $config;
    }

    private function entityManager(): EntityManagerInterface
    {
        $models = [
            self::CHAT_MODEL => $this->model('Groq', 'qwen-chat', 'chat'),
            self::IMAGE_DEFAULT => $this->model('OpenAI', 'gpt-image-1', 'text2pic'),
            self::PICKED_IMAGE_MODEL => $this->model('Google', 'imagen-4', 'text2pic'),
        ];
        $modelRepository = $this->createStub(EntityRepository::class);
        $modelRepository->method('find')->willReturnCallback(static fn (mixed $id): ?Model => $models[(int) $id] ?? null);
        $userRepository = $this->createStub(EntityRepository::class);
        $userRepository->method('find')->willReturn($this->createStub(User::class));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [User::class, $userRepository],
            [Model::class, $modelRepository],
        ]);

        return $em;
    }

    private function model(string $service, string $providerId, string $tag): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($providerId);
        $model->method('getTag')->willReturn($tag);
        $model->method('getJson')->willReturn([]);

        return $model;
    }

    private function message(): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(1001);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('/pic a red fox');
        $message->method('getTopic')->willReturn('tools:pic');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $message;
    }
}

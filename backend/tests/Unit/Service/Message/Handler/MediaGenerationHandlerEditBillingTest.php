<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
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
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Billing of an edit follows what the provider produced (#2404): quality
 * `high` (sent to the image tool) and the size the model rendered, mapped to
 * the priced square / landscape / portrait key.
 */
final class MediaGenerationHandlerEditBillingTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private string $uploadDir = '';
    private string $photo = '';

    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private MediaGenerationHandler $handler;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-edit-billing-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/7', 0o775, true);
        $this->photo = $this->uploadDir.'/7/shop-front.png';
        file_put_contents($this->photo, (string) base64_decode(self::PNG));

        $this->aiFacade = $this->createMock(AiFacade::class);
        $promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $promptExtractor->method('extract')->willReturn([
            'prompt' => 'Add a 4x4 grid of muntins to the large left window, change nothing else.',
            'media_type' => 'image',
        ]);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(151);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $mediaJobConfig->method('isAsyncJobsEnabled')->willReturn(false);
        $mediaJobConfig->method('maxActiveJobsPerUser')->willReturn(16);
        $registrar = $this->createMock(GeneratedFileRegistrar::class);
        $registrar->method('register')->willReturn($this->createMock(File::class));

        $this->bootstrapEditModel();

        $this->handler = new MediaGenerationHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->em,
            new NullLogger(),
            $promptExtractor,
            new UserUploadPathBuilder(),
            $this->createMock(ThumbnailService::class),
            $rateLimitService,
            new MediaErrorMessageBuilder(),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(MediaCancellationStore::class),
            $mediaJobConfig,
            $this->createMock(MediaJobService::class),
            $this->createMock(MediaJobDispatcher::class),
            $this->createMock(MediaJobMessageSync::class),
            $registrar,
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createMock(ConversationFileCatalog::class),
            $this->uploadDir,
            'https://app.example.test',
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->uploadDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->uploadDir);
    }

    public function testALandscapeEditIsBilledAtHighQualityAndTheRenderedSize(): void
    {
        $this->aiFacade->expects(self::once())
            ->method('generateImage')
            ->with(
                self::anything(),
                7,
                self::callback(fn (array $options): bool => 'high' === ($options['quality'] ?? null)
                    && [$this->photo] === ($options['images'] ?? null)),
            )
            ->willReturn([
                'images' => [['url' => 'data:image/png;base64,'.self::PNG, 'size' => '1536x1024']],
                'provider' => 'openai',
                'model' => 'gpt-image-1.5',
                'image_count' => 1,
            ]);

        $result = $this->edit();

        self::assertSame(
            ['images' => 1, 'quality' => 'high', 'size' => '1536x1024'],
            $result['metadata']['media_usage'] ?? null,
        );
    }

    public function testAnEditWithoutARenderedSizeKeepsTheRequestedSize(): void
    {
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => [['url' => 'data:image/png;base64,'.self::PNG]],
            'provider' => 'google',
            'model' => 'gemini-3.1-flash-image',
            'image_count' => 1,
        ]);

        $result = $this->edit();

        self::assertSame('1024x1024', $result['metadata']['media_usage']['size'] ?? null);
        self::assertSame('high', $result['metadata']['media_usage']['quality'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function edit(): array
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Füge sprossen in das linke grosse fenster');
        $message->method('getLanguage')->willReturn('de');
        $message->method('getId')->willReturn(2001);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $this->handler->handle(
            $message,
            [],
            ['language' => 'de', 'media_type' => 'image'],
            null,
            ['reference_image_paths' => [$this->photo]],
        );
    }

    private function bootstrapEditModel(): void
    {
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($this->createMock(User::class));

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-image-1.5');
        $model->method('getName')->willReturn('gpt-image-1.5');
        $model->method('getTag')->willReturn('text2pic');
        $model->method('getJson')->willReturn(['features' => ['image', 'pic2pic']]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }
}

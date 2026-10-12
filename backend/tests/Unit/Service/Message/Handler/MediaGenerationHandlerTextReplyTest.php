<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Exception\ProviderException;
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
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An image model that answers with text instead of an image (#2406): the chat
 * reply quotes what the model said and names the way out, instead of the
 * generic "could not be generated" copy.
 */
final class MediaGenerationHandlerTextReplyTest extends TestCase
{
    private string $uploadDir = '';

    private AiFacade&MockObject $aiFacade;
    private MediaPromptExtractor&MockObject $promptExtractor;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;
    private GeneratedFileRegistrar&MockObject $generatedFileRegistrar;
    private MediaJobConfig&MockObject $mediaJobConfig;
    private MediaGenerationHandler $handler;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-text-reply-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);

        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->generatedFileRegistrar = $this->createMock(GeneratedFileRegistrar::class);
        $this->mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $this->mediaJobConfig->method('isAsyncJobsEnabled')->willReturn(false);
        $this->mediaJobConfig->method('maxActiveJobsPerUser')->willReturn(16);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);

        $this->handler = new MediaGenerationHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->em,
            new NullLogger(),
            $this->promptExtractor,
            new UserUploadPathBuilder(),
            $this->createMock(ThumbnailService::class),
            $this->rateLimitService,
            new MediaErrorMessageBuilder(),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(MediaCancellationStore::class),
            $this->mediaJobConfig,
            $this->createMock(MediaJobService::class),
            $this->createMock(MediaJobDispatcher::class),
            $this->createMock(MediaJobMessageSync::class),
            $this->generatedFileRegistrar,
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createMock(ConversationFileCatalog::class),
            $this->uploadDir,
            'https://app.example.test',
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->uploadDir);
        }
    }

    public function testATextOnlyReplyIsQuotedWithTheRecovery(): void
    {
        $this->bootstrapImageModel();
        $this->modelConfigService->method('getDefaultModel')->willReturn(371);
        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'Wie viele Fenster hat dieses Haus?',
            'media_type' => 'image',
        ]);
        $this->aiFacade->method('generateImage')
            ->willThrowException(ProviderException::noImage('google', 'gemini-3.1-flash-image', '10', 'STOP'));
        $this->generatedFileRegistrar->expects(self::never())->method('register');

        $result = $this->handler->handle(
            $this->messageStub('Wie viele Fenster hat dieses Haus? Antworte nur mit einer Zahl.'),
            [],
            ['language' => 'de', 'media_type' => 'image'],
        );

        self::assertSame(
            "Google hat kein Bild erstellt, sondern mit Text geantwortet:\n\n> 10\n\n"
            .'Formuliere die Anfrage als Bildanweisung um oder wähle ein anderes Bildmodell.',
            $result['content'],
        );
        self::assertStringNotContainsString('returned text instead', (string) $result['content']);
    }

    public function testANoImageReplyWithoutTextKeepsTheGenericMessage(): void
    {
        $this->bootstrapImageModel();
        $this->modelConfigService->method('getDefaultModel')->willReturn(371);
        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'a lighthouse at dusk',
            'media_type' => 'image',
        ]);
        $this->aiFacade->method('generateImage')
            ->willThrowException(ProviderException::noImage('google', 'gemini-3.1-flash-image', null, 'STOP'));

        $result = $this->handler->handle(
            $this->messageStub('/pic a lighthouse'),
            [],
            ['topic' => 'tools:pic', 'language' => 'en', 'media_type' => 'image'],
        );

        self::assertSame(
            'Sorry, the image could not be generated right now. Please try again or use a different model.',
            $result['content'],
        );
    }

    private function bootstrapImageModel(): void
    {
        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-image-1');
        $model->method('getName')->willReturn('GPT Image');
        $model->method('getTag')->willReturn('text2pic');
        $model->method('getJson')->willReturn([]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    private function messageStub(string $text): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getId')->willReturn(1001);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $message;
    }
}

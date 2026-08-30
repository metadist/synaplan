<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\FeedbackConfigService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\Vision\VisionModelResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 4 Part C: "draw a cat" → "what breed is it?" used to be answered from
 * the text prompt alone, because only USER turns ever contributed image content
 * — the model could not see the picture it had just produced.
 *
 * The inclusion is flag-gated and capped, so these tests pin both the new
 * behaviour and the unchanged default: with the flag off the request must look
 * exactly as it did before.
 */
final class ChatHandlerGeneratedImageVisionTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/chat-generated-vision-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testGeneratedImageIsAttachedToTheAssistantTurnThatProducedIt(): void
    {
        $handler = $this->handler([$this->imageFile(1, 'cat.png', 500)]);

        $messages = $this->buildStreamingMessages($handler, ['include_generated_images' => true]);

        $assistant = $this->firstAssistantMessage($messages);
        $this->assertIsArray($assistant['content'], 'The generated picture must ride along as image content');
        $this->assertSame('text', $assistant['content'][0]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $assistant['content'][1]['image_url']['url']);
    }

    /**
     * With the flag off no pixels are sent (the token-costly opt-in stays off),
     * but the assistant turn must still carry a cheap text reference to the
     * media it produced — otherwise the model denies it exists on a follow-up
     * (#1596).
     */
    public function testGeneratedMediaIsReferencedInTextWhileTheFlagIsOff(): void
    {
        $handler = $this->handler([$this->imageFile(1, 'cat.png', 500)]);

        $content = $this->firstAssistantMessage($this->buildStreamingMessages($handler, []))['content'];

        $this->assertIsString($content, 'No image bytes may be sent while the flag is off');
        $this->assertStringContainsString('cat.png', $content);
        $this->assertStringContainsString('do not claim otherwise', $content);
    }

    /**
     * The async media path clears the assistant text
     * ({@see \App\Service\Media\MediaJobMessageSync}); the generated-media
     * reference gives the turn non-empty content so it is not dropped by the
     * empty-assistant filter (#1115) and the media stays visible (#1596).
     */
    public function testGeneratedMediaTurnWithClearedTextSurvivesAsAReference(): void
    {
        $handler = $this->handler([$this->imageFile(1, 'cat.png', 500)]);

        $assistantTurn = $this->message(500, 'OUT', ''); // text cleared by async sync
        $current = $this->message(501, 'IN', 'What breed is it?');

        $method = new \ReflectionMethod(ChatHandler::class, 'buildStreamingMessages');
        $messages = $method->invoke($handler, null, [$assistantTurn, $current], $current, []);

        $content = $this->firstAssistantMessage($messages)['content'];
        $this->assertIsString($content);
        $this->assertStringContainsString('cat.png', $content);
    }

    /**
     * With the flag on the pixels ride along AND the text reference is present,
     * so the media is both visible and unambiguously acknowledged.
     */
    public function testGeneratedImageCarriesBothPixelsAndTextReferenceWithFlagOn(): void
    {
        $handler = $this->handler([$this->imageFile(1, 'cat.png', 500)]);

        $content = $this->firstAssistantMessage(
            $this->buildStreamingMessages($handler, ['include_generated_images' => true]),
        )['content'];

        $this->assertIsArray($content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertStringContainsString('cat.png', $content[0]['text']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']['url']);
    }

    public function testOnlyTheNewestImagesFitTheBudget(): void
    {
        $files = [];
        for ($i = 1; $i <= GeneratedImageVisionFlag::MAX_GENERATED_IMAGES + 2; ++$i) {
            $files[] = $this->imageFile($i, 'cat'.$i.'.png', 500);
        }

        $handler = $this->handler($files);

        $messages = $this->buildStreamingMessages($handler, ['include_generated_images' => true]);

        $images = array_filter(
            $this->firstAssistantMessage($messages)['content'],
            static fn (array $part): bool => 'image_url' === $part['type'],
        );
        $this->assertCount(GeneratedImageVisionFlag::MAX_GENERATED_IMAGES, $images);
    }

    /**
     * A document the assistant generated is not something a vision model can
     * read — it must never be turned into an image part.
     */
    public function testGeneratedDocumentsAreNotSentAsImages(): void
    {
        $handler = $this->handler([$this->file(1, 'report.docx', 500)]);

        $messages = $this->buildStreamingMessages($handler, ['include_generated_images' => true]);

        $this->assertIsString($this->firstAssistantMessage($messages)['content']);
    }

    /**
     * An image the USER uploaded is already covered by the user-turn path; the
     * generated-image budget must not spend itself on it a second time.
     */
    public function testUploadedImagesAreLeftToTheUserTurnPath(): void
    {
        $handler = $this->handler([$this->imageFile(1, 'photo.png', 500, source: 'web_upload')]);

        $messages = $this->buildStreamingMessages($handler, ['include_generated_images' => true]);

        $this->assertIsString($this->firstAssistantMessage($messages)['content']);
    }

    public function testFlagAndModelCapabilityBothDecideWhetherImagesAreIncluded(): void
    {
        foreach ([
            'flag off, vision model' => [false, true, false],
            'flag on, blind model' => [true, false, false],
            'flag on, vision model' => [true, true, true],
        ] as $case => [$flagEnabled, $modelSeesImages, $expected]) {
            $handler = $this->handler([], flagEnabled: $flagEnabled, modelSeesImages: $modelSeesImages);

            $method = new \ReflectionMethod(ChatHandler::class, 'shouldIncludeGeneratedImages');
            $this->assertSame($expected, $method->invoke($handler, 42, 7), $case);
        }
    }

    /**
     * @param list<File> $threadFiles
     */
    private function handler(array $threadFiles, bool $flagEnabled = true, bool $modelSeesImages = true): ChatHandler
    {
        $fileRepository = $this->createMock(FileRepository::class);
        $fileRepository->method('findFilesByMessageIds')->willReturn($threadFiles);
        $fileRepository->method('findOneBy')->willReturn(null);

        $model = $this->createMock(Model::class);
        $model->method('hasFeature')->willReturnCallback(
            static fn (string $feature): bool => 'vision' === $feature && $modelSeesImages,
        );
        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->method('find')->willReturn($model);

        $flag = $this->createMock(GeneratedImageVisionFlag::class);
        $flag->method('isEnabled')->willReturn($flagEnabled);

        return new ChatHandler(
            $this->createMock(AiFacade::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $this->createMock(ModelConfigService::class),
            $modelRepository,
            new NullLogger(),
            $this->createMock(VectorSearchService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->uploadDir,
            new UserUploadPathBuilder(),
            $this->createMock(UserMemoryService::class),
            new FeedbackConfigService($this->createStub(ConfigRepository::class)),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(DocumentImageCatalog::class),
            new TimeContextBuilder(),
            new KnowledgeContextFormatter(),
            $this->createMock(VisionModelResolver::class),
            $this->createMock(DigestSearchService::class),
            $this->createMock(MessageDigestConfig::class),
            new ConversationFileCatalog($fileRepository, $this->uploadDir),
            $flag,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function buildStreamingMessages(ChatHandler $handler, array $options): array
    {
        $assistantTurn = $this->message(500, 'OUT', 'Here is your cat.');
        $current = $this->message(501, 'IN', 'What breed is it?');

        $method = new \ReflectionMethod(ChatHandler::class, 'buildStreamingMessages');

        return $method->invoke($handler, null, [$assistantTurn, $current], $current, $options);
    }

    /**
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     *
     * @return array{role: string, content: string|array<int, array<string, mixed>>}
     */
    private function firstAssistantMessage(array $messages): array
    {
        foreach ($messages as $message) {
            if ('assistant' === $message['role']) {
                return $message;
            }
        }

        $this->fail('The assistant turn was dropped from the request');
    }

    private function message(int $id, string $direction, string $text): Message
    {
        $message = (new Message())->setUserId(7)->setDirection($direction)->setText($text);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function imageFile(int $id, string $path, int $messageId, string $source = 'generated'): File
    {
        file_put_contents($this->uploadDir.'/'.$path, base64_decode(self::PNG));

        return $this->buildFile($id, $path, $messageId, $source);
    }

    private function file(int $id, string $path, int $messageId, string $source = 'generated'): File
    {
        file_put_contents($this->uploadDir.'/'.$path, 'content');

        return $this->buildFile($id, $path, $messageId, $source);
    }

    private function buildFile(int $id, string $path, int $messageId, string $source): File
    {
        $file = (new File())
            ->setUserId(7)
            ->setFilePath($path)
            ->setFileType(pathinfo($path, PATHINFO_EXTENSION))
            ->setFileName(basename($path))
            ->setFileSize(7)
            ->setFileMime('application/octet-stream')
            ->setFileText('')
            ->setStatus('processed');
        $file->setSource($source);
        $file->setMessageId($messageId);

        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}

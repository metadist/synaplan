<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #1382 — an image generated earlier in the conversation could never be
 * placed into a document because the model was never told it exists. The
 * officemaker system prompt now carries the catalog of usable markers.
 */
class ChatHandlerDocumentImageContextTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/synaplan-doc-images-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testOfficemakerPromptListsAnImageFromTheConversation(): void
    {
        file_put_contents($this->uploadDir.'/cat.jpg', 'image');
        $generated = $this->imageFile(377, 'cat.jpg');

        $context = $this->buildContext([$generated], 'officemaker');

        $this->assertStringContainsString('## Images available for this document', $context);
        $this->assertStringContainsString('{{IMAGE:file:377}}', $context);
        $this->assertStringContainsString('cat.jpg', $context);
    }

    public function testOfficemakerPromptForbidsMarkersWithoutAnyImage(): void
    {
        $context = $this->buildContext([], 'officemaker');

        $this->assertStringContainsString('NO images available', $context);
    }

    public function testOtherTopicsKeepTheirPromptUnchanged(): void
    {
        file_put_contents($this->uploadDir.'/cat.jpg', 'image');

        $this->assertSame('', $this->buildContext([$this->imageFile(377, 'cat.jpg')], 'general'));
    }

    /**
     * @param list<File> $conversationImages
     */
    private function buildContext(array $conversationImages, string $topic): string
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findImagesByMessageIds')->willReturn($conversationImages);

        $threadMessage = (new Message())->setUserId(7);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($threadMessage, 500);

        $method = new \ReflectionMethod(ChatHandler::class, 'buildDocumentImageContext');

        return (string) $method->invoke(
            $this->handler(new DocumentImageCatalog($repository, $this->uploadDir)),
            (new Message())->setUserId(7),
            [$threadMessage],
            $topic,
            [],
        );
    }

    private function handler(DocumentImageCatalog $catalog): ChatHandler
    {
        return new ChatHandler(
            $this->createMock(AiFacade::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(ModelRepository::class),
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
            $catalog,
            new TimeContextBuilder(),
            new \App\Service\Knowledge\KnowledgeContextFormatter(),
            $this->createMock(\App\Service\Vision\VisionModelResolver::class),
            $this->createMock(\App\Service\Digest\DigestSearchService::class),
            $this->createMock(\App\Service\Digest\MessageDigestConfig::class),
        );
    }

    private function imageFile(int $id, string $path): File
    {
        $file = (new File())
            ->setUserId(7)
            ->setFilePath($path)
            ->setFileType('jpg')
            ->setFileName(basename($path))
            ->setFileSize(5)
            ->setFileMime('image/jpeg')
            ->setFileText('')
            ->setStatus('processed');
        $file->setSource('generated');

        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}

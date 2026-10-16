<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Prompt;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentImageCatalog;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\Prompt\ChatSystemPromptBuilder;
use App\Service\Prompt\TimeContextBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #1382 — an image generated earlier in the conversation could never be
 * placed into a document because the model was never told it exists. The
 * officemaker system prompt now carries the catalog of usable markers.
 */
final class ChatPromptImageContextTest extends TestCase
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
     * Cross-turn image edits: told nothing, the mediamaker prompt turns "make
     * the car blue" into a full scene description and the image model redraws
     * the picture instead of editing it.
     */
    public function testMediamakerPromptNamesTheImageBeingEdited(): void
    {
        $context = $this->buildMediaEditContext('mediamaker', ['media_edit_source_name' => 'car-sunset.png']);

        $this->assertStringContainsString('You are editing an existing image', $context);
        $this->assertStringContainsString('car-sunset.png', $context);
        $this->assertStringContainsString('keep everything else unchanged', $context);
    }

    public function testMediamakerPromptIsUnchangedForAFreshGeneration(): void
    {
        $this->assertSame('', $this->buildMediaEditContext('mediamaker', []));
        $this->assertSame('', $this->buildMediaEditContext('mediamaker', ['media_edit_source_name' => '  ']));
    }

    public function testMediaEditContextIsScopedToTheMediamakerTopic(): void
    {
        $this->assertSame('', $this->buildMediaEditContext('general', ['media_edit_source_name' => 'car-sunset.png']));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildMediaEditContext(string $topic, array $options): string
    {
        return ChatPromptBlocks::mediaEditContext($topic, $options);
    }

    /**
     * @param list<File> $conversationImages
     */
    private function buildContext(array $conversationImages, string $topic): string
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findFilesByMessageIds')->willReturn($conversationImages);

        $threadMessage = (new Message())->setUserId(7);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($threadMessage, 500);

        return $this->promptBuilder(new DocumentImageCatalog(new ConversationFileCatalog($repository, $this->uploadDir)))->documentImageContext(
            (new Message())->setUserId(7),
            [$threadMessage],
            $topic,
            [],
        );
    }

    private function promptBuilder(DocumentImageCatalog $catalog): ChatSystemPromptBuilder
    {
        return new ChatSystemPromptBuilder(
            new TimeContextBuilder(),
            $catalog,
            $this->createStub(ConversationFileCatalog::class),
            new NullLogger(),
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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentImage;
use App\Service\File\DocumentImageCatalog;
use PHPUnit\Framework\TestCase;

class DocumentImageCatalogTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/document-image-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testAttachmentsOfTheCurrentMessageComeFirst(): void
    {
        $attachment = $this->imageFile(11, 7, 'now.png');
        $threadImage = $this->imageFile(9, 7, 'earlier.png');
        $message = (new Message())->setUserId(7)->addFile($attachment);

        $images = $this->catalog($this->repository([$threadImage]))
            ->build($message, [$this->threadMessage(500)]);

        $this->assertSame(['file:11', 'file:9'], array_map(static fn (DocumentImage $i): string => $i->reference, $images));
        $this->assertSame(DocumentImage::ORIGIN_ATTACHED, $images[0]->origin);
        $this->assertSame('now.png', $images[0]->name);
    }

    /**
     * The #1382 case: a picture generated three turns ago only hangs off BFILES
     * via its originating message, never off the message relation.
     */
    public function testGeneratedImageOfTheConversationIsOffered(): void
    {
        $generated = $this->imageFile(377, 7, 'cat.jpg', source: 'generated');
        $message = (new Message())->setUserId(7);

        $images = $this->catalog($this->repository([$generated]))
            ->build($message, [$this->threadMessage(500)]);

        $this->assertCount(1, $images);
        $this->assertSame('file:377', $images[0]->reference);
        $this->assertSame(DocumentImage::ORIGIN_GENERATED, $images[0]->origin);
    }

    public function testAttachedImagesOfThreadMessagesAreOffered(): void
    {
        $uploaded = $this->imageFile(21, 7, 'chart.png');
        $threadMessage = $this->threadMessage(500)->addFile($uploaded);

        $images = $this->catalog($this->repository([]))
            ->build((new Message())->setUserId(7), [$threadMessage]);

        $this->assertSame(['file:21'], array_map(static fn (DocumentImage $i): string => $i->reference, $images));
        $this->assertSame(DocumentImage::ORIGIN_UPLOADED, $images[0]->origin);
    }

    public function testUpstreamNodePathResolvesToItsFileRow(): void
    {
        $generated = $this->imageFile(88, 7, 'render.png', source: 'generated');
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['userId' => 7, 'filePath' => 'render.png'])
            ->willReturn($generated);
        $repository->method('findFilesByMessageIds')->willReturn([]);

        $images = $this->catalog($repository)->build(
            (new Message())->setUserId(7),
            [],
            ['/api/v1/files/uploads/render.png'],
        );

        $this->assertSame(['file:88'], array_map(static fn (DocumentImage $i): string => $i->reference, $images));
    }

    public function testSkipsNonImagesMissingFilesAndDuplicates(): void
    {
        $duplicate = $this->imageFile(9, 7, 'earlier.png');
        $document = $this->imageFile(31, 7, 'report.docx');
        $missing = $this->imageFile(32, 7, 'gone.png', onDisk: false);
        $threadMessage = $this->threadMessage(500)->addFile($duplicate)->addFile($document)->addFile($missing);

        $images = $this->catalog($this->repository([$duplicate]))
            ->build((new Message())->setUserId(7), [$threadMessage]);

        $this->assertSame(['file:9'], array_map(static fn (DocumentImage $i): string => $i->reference, $images));
    }

    public function testCapsTheOfferedImagesAndKeepsTheNewestOnes(): void
    {
        $files = [];
        for ($id = 1; $id <= 12; ++$id) {
            $files[] = $this->imageFile($id, 7, 'image-'.$id.'.png');
        }

        $images = $this->catalog($this->repository($files))
            ->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $this->assertCount(8, $images);
        $this->assertSame('file:12', $images[0]->reference);
        $this->assertSame('file:5', $images[7]->reference);
    }

    public function testPromptBlockListsEveryOfferedMarker(): void
    {
        $catalog = $this->catalog($this->repository([]));
        $block = $catalog->renderPromptBlock([
            new DocumentImage('file:377', 'cat.jpg', DocumentImage::ORIGIN_GENERATED, '/tmp/cat.jpg'),
        ]);

        $this->assertStringContainsString('## Images available for this document', $block);
        $this->assertStringContainsString('{{IMAGE:file:377}}', $block);
        $this->assertStringContainsString('cat.jpg', $block);
        $this->assertStringContainsString('generated earlier in this conversation', $block);
    }

    public function testPromptBlockForbidsMarkersWhenNoImageIsAvailable(): void
    {
        $block = $this->catalog($this->repository([]))->renderPromptBlock([]);

        $this->assertStringContainsString('NO images available', $block);
        $this->assertStringContainsString('Do NOT write any', $block);
    }

    private function catalog(FileRepository $repository): DocumentImageCatalog
    {
        return new DocumentImageCatalog(new ConversationFileCatalog($repository, $this->uploadDir));
    }

    /**
     * @param list<File> $linkedImages
     */
    private function repository(array $linkedImages): FileRepository
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findFilesByMessageIds')->willReturn($linkedImages);
        $repository->method('findOneBy')->willReturn(null);

        return $repository;
    }

    private function threadMessage(int $id): Message
    {
        $message = (new Message())->setUserId(7);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function imageFile(int $id, int $userId, string $path, string $source = 'web_upload', bool $onDisk = true): File
    {
        if ($onDisk) {
            file_put_contents($this->uploadDir.'/'.$path, 'image');
        }

        $file = (new File())
            ->setUserId($userId)
            ->setFilePath($path)
            ->setFileType(pathinfo($path, PATHINFO_EXTENSION))
            ->setFileName(basename($path))
            ->setFileSize(5)
            ->setFileMime('image/png')
            ->setFileText('')
            ->setStatus('processed');
        $file->setSource($source);

        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}

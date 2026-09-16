<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use PHPUnit\Framework\TestCase;

class ConversationFileCatalogTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/conversation-file-catalog-'.bin2hex(random_bytes(8));
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
        $attachment = $this->file(11, 'now.png');
        $earlier = $this->file(9, 'earlier.png', messageId: 400);
        $message = (new Message())->setUserId(7)->setDirection('IN')->addFile($attachment);

        $catalog = $this->catalog($this->repository([$earlier]))
            ->build($message, [$this->threadMessage(400)]);

        $this->assertSame(['file:11', 'file:9'], $this->references($catalog));
        $this->assertSame(ConversationFile::ORIGIN_ATTACHED, $catalog[0]->origin);
        $this->assertSame('now.png', $catalog[0]->displayName);
    }

    /**
     * The bug this catalog exists for: a picture generated three turns ago only
     * hangs off BFILES via BMESSAGEID, so a message-relation lookup sees nothing.
     */
    public function testGeneratedImageOfTheConversationIsOffered(): void
    {
        $generated = $this->file(377, 'cat.png', source: 'generated', messageId: 500);

        $catalog = $this->catalog($this->repository([$generated]))
            ->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $this->assertSame(['file:377'], $this->references($catalog));
        $this->assertSame(ConversationFile::ORIGIN_GENERATED, $catalog[0]->origin);
        $this->assertSame(ConversationFile::CATEGORY_IMAGE, $catalog[0]->category);
    }

    public function testNonImageArtifactsAreCatalogedToo(): void
    {
        $report = $this->file(21, 'report.docx', source: 'generated', messageId: 500);
        $voice = $this->file(22, 'note.mp3', messageId: 500);

        $catalog = $this->catalog($this->repository([$voice, $report]))
            ->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $categories = array_map(static fn (ConversationFile $f): string => $f->category, $catalog);
        $this->assertContains(ConversationFile::CATEGORY_DOCUMENT, $categories);
        $this->assertContains(ConversationFile::CATEGORY_AUDIO, $categories);
    }

    /**
     * Installs that predate GeneratedFileRegistrar have the picture on the
     * message only — no BFILES row exists to look up.
     */
    public function testLegacyGeneratedPathWithoutFileRowIsOffered(): void
    {
        file_put_contents($this->uploadDir.'/legacy.png', 'image');
        $out = $this->threadMessage(600)->setDirection('OUT')->setFilePath('legacy.png');

        $catalog = $this->catalog($this->repository([]))
            ->build((new Message())->setUserId(7), [$out]);

        $this->assertSame(['path:legacy.png'], $this->references($catalog));
        $this->assertSame(ConversationFile::ORIGIN_GENERATED, $catalog[0]->origin);
        $this->assertNull($catalog[0]->fileId);
    }

    public function testLegacyPathIsSkippedWhenTheFileRowAlreadyCoversIt(): void
    {
        $generated = $this->file(88, 'render.png', source: 'generated', messageId: 600);
        $out = $this->threadMessage(600)->setDirection('OUT')->setFilePath('render.png');

        $catalog = $this->catalog($this->repository([$generated]))
            ->build((new Message())->setUserId(7), [$out]);

        $this->assertSame(['file:88'], $this->references($catalog));
    }

    /**
     * BFILES.BFILEPATH can still hold the public serve URL on older rows.
     * The catalog must strip that prefix so both the absolute path and the
     * relativePath handed to vision resolve under the upload dir (#1596).
     */
    public function testStoredServePrefixIsNormalizedOnCollect(): void
    {
        file_put_contents($this->uploadDir.'/cat.png', 'content');
        $generated = $this->file(377, 'cat.png', source: 'generated', messageId: 500, onDisk: false);
        $generated->setFilePath('/api/v1/files/uploads/cat.png');

        $catalog = $this->catalog($this->repository([$generated]))
            ->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $this->assertCount(1, $catalog);
        $this->assertSame('cat.png', $catalog[0]->relativePath);
        $this->assertSame(realpath($this->uploadDir.'/cat.png'), $catalog[0]->absolutePath);
    }

    public function testUpstreamNodePathResolvesToItsFileRow(): void
    {
        $generated = $this->file(88, 'render.png', source: 'generated');
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['userId' => 7, 'filePath' => 'render.png'])
            ->willReturn($generated);
        $repository->method('findFilesByMessageIds')->willReturn([]);

        $catalog = $this->catalog($repository)->build(
            (new Message())->setUserId(7),
            [],
            ['/api/v1/files/uploads/render.png'],
        );

        $this->assertSame(['file:88'], $this->references($catalog));
        $this->assertSame(ConversationFile::ORIGIN_GENERATED, $catalog[0]->origin);
    }

    public function testSkipsMissingFilesDuplicatesAndPathEscapes(): void
    {
        $duplicate = $this->file(9, 'earlier.png', messageId: 500);
        $missing = $this->file(32, 'gone.png', onDisk: false);
        $escaping = $this->file(33, '../../etc/passwd');
        $threadMessage = $this->threadMessage(500)->addFile($duplicate)->addFile($missing)->addFile($escaping);

        $catalog = $this->catalog($this->repository([$duplicate]))
            ->build((new Message())->setUserId(7), [$threadMessage]);

        $this->assertSame(['file:9'], $this->references($catalog));
    }

    public function testCapsEachCategorySeparatelySoDocumentsCannotHideThePicture(): void
    {
        $files = [];
        for ($id = 1; $id <= 10; ++$id) {
            $files[] = $this->file(100 + $id, 'doc-'.$id.'.docx', messageId: 500 + $id);
        }
        // The oldest entry of the thread, and the only image in it.
        $files[] = $this->file(1, 'photo.png', source: 'generated', messageId: 400);

        $catalog = $this->catalog($this->repository($files))
            ->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $images = array_values(array_filter($catalog, static fn (ConversationFile $f): bool => $f->isImage()));
        $this->assertCount(1, $images, 'the picture must survive a burst of documents');
        $this->assertSame('file:1', $images[0]->reference);
        $this->assertCount(ConversationFileCatalog::MAX_FILES_PER_CATEGORY, array_filter(
            $catalog,
            static fn (ConversationFile $f): bool => ConversationFile::CATEGORY_DOCUMENT === $f->category,
        ));
    }

    public function testLatestImagePrefersTheAttachmentThenTheNewestThreadImage(): void
    {
        $older = $this->file(5, 'older.png', source: 'generated', messageId: 400);
        $newer = $this->file(6, 'newer.png', source: 'generated', messageId: 500);
        $catalogService = $this->catalog($this->repository([$newer, $older]));

        $thread = [$this->threadMessage(400), $this->threadMessage(500)];
        $fromHistory = $catalogService->latestImage($catalogService->build((new Message())->setUserId(7), $thread));
        $this->assertNotNull($fromHistory);
        $this->assertSame('file:6', $fromHistory->reference);

        $attached = $this->file(2, 'attached.png');
        $withAttachment = $catalogService->build(
            (new Message())->setUserId(7)->addFile($attached),
            $thread,
        );
        $latest = $catalogService->latestImage($withAttachment);
        $this->assertNotNull($latest);
        $this->assertSame('file:2', $latest->reference);
    }

    public function testLatestImageIgnoresDocuments(): void
    {
        $report = $this->file(50, 'report.docx', source: 'generated', messageId: 500);
        $service = $this->catalog($this->repository([$report]));

        $this->assertNull($service->latestImage($service->build((new Message())->setUserId(7), [$this->threadMessage(500)])));
    }

    /**
     * #1689: a document the user hands over now is what the turn is about, not
     * the picture generated earlier.
     */
    public function testDocumentInFocusIsTheAttachedDocument(): void
    {
        $trench = $this->file(93, 'trench.png', source: 'generated', messageId: 460);
        $route = $this->file(94, 'route.docx');
        $service = $this->catalog($this->repository([$trench]));

        $focus = $service->documentInFocus($service->build(
            (new Message())->setUserId(7)->setDirection('IN')->addFile($route),
            [$this->threadMessage(460)],
        ));

        $this->assertNotNull($focus);
        $this->assertSame('file:94', $focus->reference);
    }

    public function testDocumentInFocusIsTheUploadNewerThanEveryGeneratedImage(): void
    {
        $trench = $this->file(93, 'trench.png', source: 'generated', messageId: 460);
        $route = $this->file(94, 'route.docx', messageId: 465);
        $service = $this->catalog($this->repository([$route, $trench]));

        $focus = $service->documentInFocus($service->build(
            (new Message())->setUserId(7),
            [$this->threadMessage(460), $this->threadMessage(465)],
        ));

        $this->assertNotNull($focus);
        $this->assertSame('file:94', $focus->reference);
    }

    public function testNoDocumentInFocusWhenAnImageWasGeneratedAfterTheUpload(): void
    {
        $brief = $this->file(94, 'brief.docx', messageId: 460);
        $illustration = $this->file(95, 'illustration.png', source: 'generated', messageId: 465);
        $service = $this->catalog($this->repository([$illustration, $brief]));

        $this->assertNull($service->documentInFocus($service->build(
            (new Message())->setUserId(7),
            [$this->threadMessage(460), $this->threadMessage(465)],
        )));
    }

    public function testGeneratedDocumentsAndOtherMediaNeverTakeFocus(): void
    {
        $trench = $this->file(93, 'trench.png', source: 'generated', messageId: 460);
        $report = $this->file(96, 'report.docx', source: 'generated', messageId: 470);
        $voice = $this->file(97, 'note.mp3', messageId: 480);
        $service = $this->catalog($this->repository([$voice, $report, $trench]));

        $this->assertNull($service->documentInFocus($service->build(
            (new Message())->setUserId(7),
            [$this->threadMessage(460), $this->threadMessage(470), $this->threadMessage(480)],
        )));
        $this->assertNull($service->documentInFocus([]));
    }

    public function testCategoryFilterRestrictsTheCatalog(): void
    {
        $report = $this->file(50, 'report.docx', messageId: 500);
        $photo = $this->file(51, 'photo.png', messageId: 500);
        $service = $this->catalog($this->repository([$photo, $report]));

        $images = $service->build((new Message())->setUserId(7), [$this->threadMessage(500)], [], ConversationFile::CATEGORY_IMAGE);

        $this->assertSame(['file:51'], $this->references($images));
    }

    public function testFindByReferenceOnlyAcceptsOfferedReferences(): void
    {
        $photo = $this->file(51, 'photo.png', messageId: 500);
        $service = $this->catalog($this->repository([$photo]));
        $catalog = $service->build((new Message())->setUserId(7), [$this->threadMessage(500)]);

        $this->assertNotNull($service->findByReference($catalog, 'file:51'));
        $this->assertNull($service->findByReference($catalog, 'file:999'));
        $this->assertNull($service->findByReference($catalog, ''));
    }

    public function testInventoryBlockListsEntriesAndIsEmptyWithoutFiles(): void
    {
        $service = $this->catalog($this->repository([]));

        $this->assertSame('', $service->renderInventoryBlock([]));

        $block = $service->renderInventoryBlock([
            new ConversationFile('file:377', 'cat.png', ConversationFile::CATEGORY_IMAGE, ConversationFile::ORIGIN_GENERATED, '/tmp/cat.png', 'cat.png', 377),
        ]);

        $this->assertStringContainsString('## Files available in this conversation', $block);
        $this->assertStringContainsString('`file:377`', $block);
        $this->assertStringContainsString('cat.png', $block);
        $this->assertStringContainsString('generated earlier in this conversation', $block);
    }

    /**
     * @param list<ConversationFile> $catalog
     *
     * @return list<string>
     */
    private function references(array $catalog): array
    {
        return array_map(static fn (ConversationFile $file): string => $file->reference, $catalog);
    }

    private function catalog(FileRepository $repository): ConversationFileCatalog
    {
        return new ConversationFileCatalog($repository, $this->uploadDir);
    }

    /**
     * @param list<File> $linkedFiles
     */
    private function repository(array $linkedFiles): FileRepository
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findFilesByMessageIds')->willReturn($linkedFiles);
        $repository->method('findOneBy')->willReturn(null);

        return $repository;
    }

    private function threadMessage(int $id): Message
    {
        $message = (new Message())->setUserId(7);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function file(
        int $id,
        string $path,
        string $source = 'web_upload',
        bool $onDisk = true,
        ?int $messageId = null,
    ): File {
        if ($onDisk && !str_contains($path, '..')) {
            file_put_contents($this->uploadDir.'/'.$path, 'content');
        }

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

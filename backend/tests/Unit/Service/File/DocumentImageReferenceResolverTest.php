<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\File\DocumentImageReferenceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DocumentImageReferenceResolverTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/document-images-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->uploadDir);
    }

    public function testConvertsAttachedMarkerToPersistentUserOwnedReference(): void
    {
        file_put_contents($this->uploadDir.'/profile.png', 'image');
        $file = $this->imageFile(42, 7, 'profile.png');

        $message = (new Message())->setUserId(7)->addFile($file);
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 42, 'userId' => 7])
            ->willReturn($file);

        $result = $this->resolver($repository)
            ->resolve('Before {{IMAGE:attached:1}} after', $message);

        $this->assertSame('Before {{IMAGE:file:42}} after', $result['content']);
        $this->assertSame(realpath($this->uploadDir.'/profile.png'), $result['images']['file:42']);
    }

    /**
     * Issue #1228 follow-up: the model asks for an image the message does not
     * carry. Keeping the marker made the whole document generation fail, so it
     * is dropped and the document is written without the image.
     */
    public function testDropsMarkerWhenTheMessageHasNoMatchingAttachment(): void
    {
        $message = (new Message())->setUserId(7);
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($this->never())->method('findOneBy');

        $result = $this->resolver($repository)
            ->resolve("Application\n\n{{IMAGE:attached:1}}\n\nKind regards", $message);

        $this->assertSame("Application\n\nKind regards", $result['content']);
        $this->assertSame([], $result['images']);
    }

    public function testDropsPersistentMarkerTheUserDoesNotOwn(): void
    {
        $message = (new Message())->setUserId(7);
        $repository = $this->createMock(FileRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $result = $this->resolver($repository)->resolve('Before {{IMAGE:file:42}} after', $message);

        $this->assertSame('Before  after', $result['content']);
        $this->assertSame([], $result['images']);
    }

    public function testDoesNotResolveAnotherUsersPersistentImage(): void
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 42, 'userId' => 7])
            ->willReturn(null);

        $result = $this->resolver($repository)->resolvePersistent('{{IMAGE:file:42}}', 7);

        $this->assertSame([], $result);
    }

    private function resolver(FileRepository $repository): DocumentImageReferenceResolver
    {
        return new DocumentImageReferenceResolver($repository, new NullLogger(), $this->uploadDir);
    }

    private function imageFile(int $id, int $userId, string $path): File
    {
        $file = (new File())
            ->setUserId($userId)
            ->setFilePath($path)
            ->setFileType('png')
            ->setFileName(basename($path))
            ->setFileSize(5)
            ->setFileMime('image/png')
            ->setFileText('')
            ->setStatus('processed');

        $idProperty = new \ReflectionProperty(File::class, 'id');
        $idProperty->setValue($file, $id);

        return $file;
    }
}

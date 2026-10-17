<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\Message\Handler\Media\MediaReferenceResolver;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The bug this fixes: "make the car blue" right after an image was generated
 * carried no attachment, so the handler saw no reference image and produced a
 * brand new, completely different picture. The reference resolver now takes the
 * newest image of the conversation — but only when the request really is an edit.
 */
final class MediaReferenceResolverTest extends TestCase
{
    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-references-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/a', 0o775, true);
        foreach (['photo.png', 'other.png', 'notes.txt'] as $name) {
            file_put_contents($this->uploadDir.'/a/'.$name, 'bytes');
        }
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->uploadDir.'/a/*') ?: []);
        rmdir($this->uploadDir.'/a');
        rmdir($this->uploadDir);
    }

    public function testNewestConversationImageIsUsedWhenTheSorterVotedForAnEdit(): void
    {
        $newest = $this->image('file:6', 'car-sunset.png');
        $catalog = $this->catalogReturning([$newest, $this->image('file:5', 'older.png')]);

        $resolved = $this->resolve($catalog, ['input_mode' => 'reference_images', 'media_type' => 'image']);

        $this->assertNotNull($resolved);
        $this->assertSame('file:6', $resolved->reference);
    }

    /**
     * The expensive false positive: reusing an old picture for a genuinely new
     * request. Without the sorter's vote nothing is resolved.
     */
    public function testNothingIsResolvedWithoutTheEditVote(): void
    {
        $catalog = $this->catalogReturning([$this->image('file:6', 'car-sunset.png')]);

        $this->assertNull($this->resolve($catalog, ['input_mode' => 'text_only', 'media_type' => 'image']));
        $this->assertNull($this->resolve($catalog, ['media_type' => 'image']));
    }

    /**
     * A video request must never silently become image-to-video off an
     * unrelated picture from earlier in the chat.
     */
    public function testVideoAndAudioRequestsNeverPickUpAConversationImage(): void
    {
        $catalog = $this->catalogReturning([$this->image('file:6', 'car-sunset.png')]);

        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'media_type' => 'video']));
        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'media_type' => 'audio']));
    }

    /**
     * `/vid` and `/tts` reach the handler without a media_type on the
     * classification, so the topic has to carry the same veto.
     */
    public function testVideoAndAudioSlashCommandsNeverPickUpAConversationImage(): void
    {
        $catalog = $this->catalogReturning([$this->image('file:6', 'car-sunset.png')]);

        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'topic' => 'tools:vid']));
        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'topic' => 'tools:tts']));
        $this->assertNotNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'topic' => 'tools:pic']));
    }

    public function testAnEmptyConversationResolvesToNothing(): void
    {
        $catalog = $this->catalogReturning([]);

        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'media_type' => 'image']));
    }

    /**
     * Only pictures are eligible: an image-edit vote in a thread that contains
     * nothing but documents must not hand a .docx to an image model.
     */
    public function testOnlyImagesAreRequestedFromTheCatalog(): void
    {
        $catalog = $this->createMock(ConversationFileCatalog::class);
        $catalog->expects($this->once())
            ->method('build')
            ->with($this->anything(), $this->anything(), [], ConversationFile::CATEGORY_IMAGE)
            ->willReturn([]);
        $catalog->method('latestImage')->willReturn(null);

        $this->assertNull($this->resolve($catalog, ['input_mode' => 'reference_images', 'media_type' => 'image']));
    }

    public function testAttachedAndChainedImagesAreCollectedOnceEach(): void
    {
        $references = $this->resolver($this->untouchedCatalog())->resolve(
            $this->message('Make the sky purple', ['a/photo.png', 'a/notes.txt', 'a/gone.png']),
            [],
            [],
            ['reference_image_paths' => ['/api/v1/files/uploads/a/photo.png', 'a/other.png', 'a/missing.png', '']],
        );

        $this->assertSame([$this->uploadDir.'/a/photo.png', $this->uploadDir.'/a/other.png'], $references->attachedImagePaths);
        $this->assertNull($references->editSource);
    }

    public function testTheLegacyMessageFileCountsOnlyWithoutNewerAttachments(): void
    {
        $references = $this->resolver($this->untouchedCatalog())->resolve($this->message('Edit this', [], 'a/other.png'), [], [], []);

        $this->assertSame([$this->uploadDir.'/a/other.png'], $references->attachedImagePaths);
    }

    public function testImageUrlsComeFromTheTextAndTheChainOnceEach(): void
    {
        $references = $this->resolver($this->untouchedCatalog())->resolve(
            $this->message('Animate https://images.example.com/a.jpg please', []),
            [],
            [],
            ['reference_image_urls' => ['https://images.example.com/a.jpg', 'see https://images.example.com/b.png', 7]],
        );

        $this->assertSame(['https://images.example.com/a.jpg', 'https://images.example.com/b.png'], $references->imageUrlsInText);
        $this->assertSame([], $references->attachedImagePaths);
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function resolve(ConversationFileCatalog $catalog, array $classification): ?ConversationFile
    {
        return $this->resolver($catalog)->resolve(new Message(), [], $classification, [])->editSource;
    }

    private function resolver(ConversationFileCatalog $catalog): MediaReferenceResolver
    {
        return new MediaReferenceResolver($catalog, new NullLogger(), $this->uploadDir);
    }

    /**
     * A message that brings its own picture never looks into the conversation.
     */
    private function untouchedCatalog(): ConversationFileCatalog
    {
        $catalog = $this->createMock(ConversationFileCatalog::class);
        $catalog->expects($this->never())->method('build');

        return $catalog;
    }

    /**
     * @param list<string> $files
     */
    private function message(string $text, array $files, string $legacyPath = ''): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getFilePath')->willReturn($legacyPath);
        $message->method('getFiles')->willReturn(new ArrayCollection(array_map(function (string $path): File {
            $file = $this->createStub(File::class);
            $file->method('getFilePath')->willReturn($path);

            return $file;
        }, $files)));

        return $message;
    }

    /**
     * @param list<ConversationFile> $images
     */
    private function catalogReturning(array $images): ConversationFileCatalog
    {
        $catalog = $this->createStub(ConversationFileCatalog::class);
        $catalog->method('build')->willReturn($images);
        $catalog->method('latestImage')->willReturn($images[0] ?? null);

        return $catalog;
    }

    private function image(string $reference, string $name): ConversationFile
    {
        return new ConversationFile(
            $reference,
            $name,
            ConversationFile::CATEGORY_IMAGE,
            ConversationFile::ORIGIN_GENERATED,
            '/tmp/'.$name,
            $name,
            (int) filter_var($reference, FILTER_SANITIZE_NUMBER_INT),
        );
    }
}

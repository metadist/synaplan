<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\Entity\Message;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\Message\Handler\MediaGenerationHandler;
use PHPUnit\Framework\TestCase;

/**
 * The bug this fixes: "make the car blue" right after an image was generated
 * carried no attachment, so the handler saw no reference image and produced a
 * brand new, completely different picture. The handler now resolves the newest
 * image of the conversation — but only when the request really is an edit.
 */
final class MediaGenerationHandlerConversationEditTest extends TestCase
{
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

    /**
     * @param array<string, mixed> $classification
     */
    private function resolve(ConversationFileCatalog $catalog, array $classification): ?ConversationFile
    {
        $handler = (new \ReflectionClass(MediaGenerationHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MediaGenerationHandler::class, 'conversationFileCatalog'))
            ->setValue($handler, $catalog);

        $method = new \ReflectionMethod(MediaGenerationHandler::class, 'resolveConversationEditSource');

        return $method->invoke($handler, new Message(), [], $classification);
    }

    /**
     * @param list<ConversationFile> $images
     */
    private function catalogReturning(array $images): ConversationFileCatalog
    {
        $catalog = $this->createMock(ConversationFileCatalog::class);
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

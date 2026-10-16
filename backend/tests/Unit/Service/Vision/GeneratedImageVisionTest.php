<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vision;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Repository\FileRepository;
use App\Repository\ModelRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\Message\Chat\ChatMessageAssembler;
use App\Service\Message\Chat\ConversationHistoryRenderer;
use App\Service\Vision\VisionAttachmentEncoder;
use App\Service\Vision\VisionModelResolver;
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
final class GeneratedImageVisionTest extends TestCase
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

    public function testGeneratedImageIsAttachedToTheFollowUpUserTurn(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $messages = $this->buildStreamingMessages($messages, ['include_generated_images' => true]);

        $this->assertIsString($this->firstAssistantMessage($messages)['content'], 'Anthropic rejects image blocks on assistant turns');
        $user = $this->lastUserMessage($messages);
        $this->assertIsArray($user['content'], 'The generated picture must ride along on the follow-up user turn');
        $this->assertSame('text', $user['content'][0]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $user['content'][1]['image_url']['url']);
    }

    /**
     * With the kill-switch off no pixels are sent, but the assistant turn must
     * still carry a cheap text reference to the media it produced — otherwise
     * the model denies it exists on a follow-up (#1596).
     */
    public function testGeneratedMediaIsReferencedInTextWhileTheFlagIsOff(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $content = $this->firstAssistantMessage($this->buildStreamingMessages($messages, []))['content'];

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
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $assistantTurn = $this->message(500, 'OUT', ''); // text cleared by async sync
        $current = $this->message(501, 'IN', 'What breed is it?');

        $built = $messages->streaming(null, [$assistantTurn, $current], $current, []);

        $content = $this->firstAssistantMessage($built)['content'];
        $this->assertIsString($content);
        $this->assertStringContainsString('cat.png', $content);
    }

    /**
     * With the flag on the pixels ride along AND the text reference is present,
     * so the media is both visible and unambiguously acknowledged.
     */
    public function testGeneratedImageCarriesBothPixelsAndTextReferenceWithFlagOn(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $messages = $this->buildStreamingMessages($messages, ['include_generated_images' => true]);

        $assistant = $this->firstAssistantMessage($messages)['content'];
        $this->assertIsString($assistant);
        $this->assertStringContainsString('cat.png', $assistant);

        $user = $this->lastUserMessage($messages)['content'];
        $this->assertIsArray($user);
        $this->assertStringStartsWith('data:image/png;base64,', $user[1]['image_url']['url']);
    }

    public function testOnlyTheNewestImagesFitTheBudget(): void
    {
        $files = [];
        for ($i = 1; $i <= GeneratedImageVisionFlag::MAX_GENERATED_IMAGES + 2; ++$i) {
            $files[] = $this->imageFile($i, 'cat'.$i.'.png', 500);
        }

        $messages = $this->assembler($files);

        $messages = $this->buildStreamingMessages($messages, ['include_generated_images' => true]);

        $content = $this->lastUserMessage($messages)['content'];
        $this->assertIsArray($content);
        $images = array_filter(
            $content,
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
        $messages = $this->assembler([$this->file(1, 'report.docx', 500)]);

        $messages = $this->buildStreamingMessages($messages, ['include_generated_images' => true]);

        $this->assertIsString($this->firstAssistantMessage($messages)['content']);
    }

    /**
     * An image the USER uploaded is already covered by the user-turn path; the
     * generated-image budget must not spend itself on it a second time.
     */
    public function testUploadedImagesAreLeftToTheUserTurnPath(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'photo.png', 500, source: 'web_upload')]);

        $messages = $this->buildStreamingMessages($messages, ['include_generated_images' => true]);

        $this->assertIsString($this->firstAssistantMessage($messages)['content']);
    }

    /**
     * The pixels ride on the USER turn, where they look like a fresh upload.
     * Without a provenance line the model answers about "the image you sent me"
     * instead of the picture it produced itself.
     */
    public function testTheUserTurnSaysWhereTheGeneratedImageCameFrom(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $content = $this->lastUserMessage(
            $this->buildStreamingMessages($messages, ['include_generated_images' => true]),
        )['content'];

        $this->assertIsArray($content);
        $text = $content[0]['text'];
        $this->assertStringContainsString('not a new upload from the user', $text);
        $this->assertStringContainsString('"cat.png"', $text);
        $this->assertStringContainsString('YOU generated earlier in this conversation', $text);
    }

    /**
     * An image the user attaches to THIS turn is what the question is about.
     * Adding the historic picture on top would send two inline payloads —
     * MAX_VISION_BASE64_LENGTH is enforced per image, so the request would
     * carry twice the intended budget — and leave the model guessing which one
     * "what is in it?" refers to.
     */
    public function testAFreshUploadDisplacesTheHistoricGeneratedImage(): void
    {
        $upload = $this->imageFile(2, 'photo.png', 501, source: 'web_upload');
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500)]);

        $content = $this->lastUserMessage($this->buildStreamingMessages(
            $messages,
            ['include_generated_images' => true, 'include_images' => true],
            currentAttachments: [$upload],
        ))['content'];

        $this->assertIsArray($content);
        $images = array_values(array_filter(
            $content,
            static fn (array $part): bool => 'image_url' === $part['type'],
        ));
        $this->assertCount(1, $images, 'Only the freshly uploaded image belongs on this turn');
        $this->assertStringNotContainsString('not a new upload from the user', $content[0]['text']);
    }

    /**
     * A stored display URL (`/api/v1/files/uploads/<rel>`) must resolve to the
     * file under the upload dir — StreamController persists that prefix on the
     * OUT message, and the encoder used to prefix $uploadDir on top of it.
     */
    public function testDisplayUrlPathIsResolvedForGeneratedVision(): void
    {
        $messages = $this->assembler([$this->imageFile(1, 'cat.png', 500, filePath: '/api/v1/files/uploads/cat.png')]);

        $content = $this->lastUserMessage(
            $this->buildStreamingMessages($messages, ['include_generated_images' => true]),
        )['content'];

        $this->assertIsArray($content);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']['url']);
    }

    public function testImageToBase64DataUrlStripsTheServePrefix(): void
    {
        $encoder = $this->encoder($this->catalog([]));
        file_put_contents($this->uploadDir.'/cat.png', base64_decode(self::PNG));

        $dataUrl = $encoder->toBase64DataUrl('/api/v1/files/uploads/cat.png');

        $this->assertIsString($dataUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    public function testFlagAndModelCapabilityBothDecideWhetherImagesAreIncluded(): void
    {
        foreach ([
            'flag off, vision model' => [false, true, false],
            'flag on, blind model' => [true, false, false],
            'flag on, vision model' => [true, true, true],
        ] as $case => [$flagEnabled, $modelSeesImages, $expected]) {
            $encoder = $this->encoder($this->catalog([]), flagEnabled: $flagEnabled, modelSeesImages: $modelSeesImages);

            $this->assertSame($expected, $encoder->shouldIncludeGeneratedImages(42, 7), $case);
        }
    }

    /**
     * @param list<File> $threadFiles
     */
    private function catalog(array $threadFiles): ConversationFileCatalog
    {
        $fileRepository = $this->createStub(FileRepository::class);
        $fileRepository->method('findFilesByMessageIds')->willReturn($threadFiles);
        $fileRepository->method('findOneBy')->willReturn(null);

        return new ConversationFileCatalog($fileRepository, $this->uploadDir);
    }

    private function encoder(ConversationFileCatalog $catalog, bool $flagEnabled = true, bool $modelSeesImages = true): VisionAttachmentEncoder
    {
        $model = $this->createStub(Model::class);
        $model->method('hasFeature')->willReturnCallback(
            static fn (string $feature): bool => 'vision' === $feature && $modelSeesImages,
        );
        $modelRepository = $this->createStub(ModelRepository::class);
        $modelRepository->method('find')->willReturn($model);

        $flag = $this->createStub(GeneratedImageVisionFlag::class);
        $flag->method('isEnabled')->willReturn($flagEnabled);

        return new VisionAttachmentEncoder($this->uploadDir, $catalog, $flag, $modelRepository, $this->createStub(VisionModelResolver::class), new NullLogger());
    }

    /**
     * @param list<File> $threadFiles
     */
    private function assembler(array $threadFiles, bool $flagEnabled = true, bool $modelSeesImages = true): ChatMessageAssembler
    {
        $catalog = $this->catalog($threadFiles);

        return new ChatMessageAssembler(
            $this->encoder($catalog, $flagEnabled, $modelSeesImages),
            new ConversationHistoryRenderer($catalog),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param list<File>           $currentAttachments files the user attached to the follow-up turn
     *
     * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function buildStreamingMessages(ChatMessageAssembler $messages, array $options, array $currentAttachments = []): array
    {
        $assistantTurn = $this->message(500, 'OUT', 'Here is your cat.');
        $current = $this->message(501, 'IN', 'What breed is it?');
        foreach ($currentAttachments as $attachment) {
            $current->addFile($attachment);
        }

        return $messages->streaming(null, [$assistantTurn, $current], $current, $options);
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

    /**
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     *
     * @return array{role: string, content: string|array<int, array<string, mixed>>}
     */
    private function lastUserMessage(array $messages): array
    {
        $last = null;
        foreach ($messages as $message) {
            if ('user' === $message['role']) {
                $last = $message;
            }
        }

        if (null === $last) {
            $this->fail('The user turn was dropped from the request');
        }

        return $last;
    }

    private function message(int $id, string $direction, string $text): Message
    {
        $message = (new Message())->setUserId(7)->setDirection($direction)->setText($text);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function imageFile(int $id, string $path, int $messageId, string $source = 'generated', ?string $filePath = null): File
    {
        file_put_contents($this->uploadDir.'/'.$path, base64_decode(self::PNG));

        return $this->buildFile($id, $filePath ?? $path, $messageId, $source);
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

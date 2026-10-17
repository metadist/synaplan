<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\File\ConversationFile;
use App\Service\Message\Handler\Media\MediaPrompt;
use App\Service\Message\Handler\Media\MediaPromptResolver;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\MediaPromptExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaPromptResolverTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $contexts = [];

    /** @var list<array{string, string}> */
    private array $statuses = [];

    public function testAnEditTellsTheExtractionWhichPictureChanges(): void
    {
        $prompt = $this->resolve([['prompt' => 'Paint the car blue', 'media_type' => 'image']], withEditSource: true);

        self::assertSame([['media_edit_source_name' => 'car.png']], $this->contexts);
        self::assertSame(['/uploads/car.png'], $prompt->references->attachedImagePaths);
        self::assertSame('car.png', $prompt->references->editSource?->displayName);
        self::assertSame([['editing', 'Editing car.png…']], $this->statuses);
    }

    public function testAVideoRequestDropsThePictureAndIsExtractedAgain(): void
    {
        $prompt = $this->resolve([
            ['prompt' => 'Only the car changes', 'media_type' => 'video'],
            ['prompt' => 'A car drives into the sunset', 'media_type' => 'video'],
        ], withEditSource: true);

        self::assertSame([['media_edit_source_name' => 'car.png'], []], $this->contexts);
        self::assertSame(['A car drives into the sunset', 'video', [], null], [$prompt->text, $prompt->mediaType, $prompt->references->attachedImagePaths, $prompt->references->editSource]);
        self::assertSame([], $this->statuses);
    }

    public function testTheMessageStandsInForAnEmptyPrompt(): void
    {
        self::assertSame('/pic a quiet harbour', $this->resolve([['prompt' => '  ', 'media_type' => 'image']], text: '/pic a quiet harbour')->text);
    }

    public function testATurnWithoutAnyTextCannotBePrompted(): void
    {
        $this->expectExceptionMessage('Unable to determine media prompt text');

        $this->resolve([['prompt' => '', 'media_type' => null]], text: '');
    }

    /**
     * @param list<array{prompt: string, media_type: string|null}> $extractions
     */
    private function resolve(array $extractions, bool $withEditSource = false, string $text = 'Mach das Auto blau'): MediaPrompt
    {
        $extractor = $this->createStub(MediaPromptExtractor::class);
        $extractor->method('extract')->willReturnCallback(function (Message $message, array $thread, array $classification, array $context = []) use (&$extractions): array {
            $this->contexts[] = $context;

            return array_shift($extractions) + ['raw' => ''];
        });
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $editSource = $withEditSource ? new ConversationFile('file:9', 'car.png', ConversationFile::CATEGORY_IMAGE, ConversationFile::ORIGIN_GENERATED, '/uploads/car.png', 'car.png', 9) : null;

        return (new MediaPromptResolver($extractor, new NullLogger()))->resolve(
            $message,
            [],
            [],
            new MediaReferences([], [], $editSource),
            function (array $event): void {
                $this->statuses[] = [$event['status'], $event['message']];
            },
        );
    }
}

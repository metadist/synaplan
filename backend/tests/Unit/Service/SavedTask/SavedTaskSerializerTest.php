<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Repository\PromptRepository;
use App\Service\SavedTask\Graph\SavedTaskSummary;
use App\Service\SavedTask\SavedTaskSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SavedTaskSerializerTest extends TestCase
{
    private PromptRepository&MockObject $prompts;
    private SavedTaskSerializer $serializer;

    protected function setUp(): void
    {
        $this->prompts = $this->createMock(PromptRepository::class);
        $this->serializer = new SavedTaskSerializer(new SavedTaskSummary(), $this->prompts);
    }

    public function testTaskIncludesTheStartOfTheInstruction(): void
    {
        $this->prompts->method('find')->willReturn(
            $this->prompt('Erstelle ein realistisches Bild einer Katze, weiches natürliches Licht, scharfe Details'),
        );

        $data = $this->serializer->task(new SavedTask(1, 12, 'Katzenbild'));

        self::assertSame(
            'Erstelle ein realistisches Bild einer Katze, weiches natürli…',
            $data['instructionPreview'],
        );
    }

    public function testShortInstructionIsNotTruncated(): void
    {
        $this->prompts->method('find')->willReturn($this->prompt("Summarize   my\ninbox"));

        $data = $this->serializer->task(new SavedTask(1, 12, 'Inbox'));

        self::assertSame('Summarize my inbox', $data['instructionPreview']);
    }

    public function testMissingPromptYieldsNullPreview(): void
    {
        $this->prompts->method('find')->willReturn(null);

        $data = $this->serializer->task(new SavedTask(1, 999, 'Orphan'));

        self::assertNull($data['instructionPreview']);
    }

    private function prompt(string $text): Prompt
    {
        $prompt = new Prompt();
        $prompt->setPrompt($text);

        return $prompt;
    }
}

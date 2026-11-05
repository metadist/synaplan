<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Multitask\Plan\ImageFileWorkArbitration;
use App\Service\Multitask\Plan\TaskPlan;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ImageFileWorkArbitrationTest extends TestCase
{
    public function testALookChangeBecomesImageGeneration(): void
    {
        $out = $this->arbiter()->adjust($this->codeRunPlan(), $this->png(
            'Kannst du das Bild so verändern, dass es etwas breiter wird und die Vögel mittig platziert sind?',
        ));

        self::assertSame('image_generation', $out['tasks'][0]['capability']);
        self::assertSame('n1', $out['reply_node']);
    }

    public function testAStampStaysFileWork(): void
    {
        $plan = $this->codeRunPlan();
        $out = $this->arbiter()->adjust($plan, $this->png('Stempel unten rechts INTERNAL drauf.'));

        self::assertSame($plan, $out);
    }

    public function testAnAmbiguousResizeAsksFirst(): void
    {
        $out = $this->arbiter()->adjust($this->codeRunPlan(), $this->png('Resize this image to half.'));

        self::assertSame('ask_user', $out['tasks'][0]['capability']);
        self::assertSame(ImageFileWorkArbitration::ARBITRATION, $out['tasks'][0]['params']['arbitration']);
        self::assertSame('image_generation', $out['tasks'][1]['capability']);
        self::assertContains('n_ask', $out['tasks'][1]['depends_on']);
        self::assertSame(['Image edit', 'File work'], $out['tasks'][0]['params']['options']);
        self::assertSame('Do you want an image edit or file work?', $out['tasks'][0]['params']['question']);
    }

    public function testAGermanAmbiguousResizeAsksInGerman(): void
    {
        $out = $this->arbiter()->adjust($this->codeRunPlan(), $this->png('Bitte das Bild skalieren.'));

        self::assertSame('Möchtest du eine Bildbearbeitung oder Dateiarbeit?', $out['tasks'][0]['params']['question']);
        self::assertSame(['Bildbearbeitung', 'Dateiarbeit'], $out['tasks'][0]['params']['options']);
    }

    public function testChoosingFileWorkRestoresCodeRun(): void
    {
        $asked = $this->arbiter()->adjust($this->codeRunPlan('print("hi")'), $this->png('Resize this image to half.'));
        $plan = TaskPlan::fromArray($asked);
        $chosen = $this->arbiter()->withChosenWork($plan, 'n_ask', 'File work');
        $work = $chosen->nodeById('n1');

        self::assertNotNull($work);
        self::assertSame('code_run', $work->capability->value);
        self::assertSame('print("hi")', $work->params['script'] ?? null);
    }

    public function testChoosingImageEditKeepsPic2Pic(): void
    {
        $asked = $this->arbiter()->adjust($this->codeRunPlan(), $this->png('Resize this image to half.'));
        $chosen = $this->arbiter()->withChosenWork(TaskPlan::fromArray($asked), 'n_ask', 'Image edit');

        self::assertSame('image_generation', $chosen->nodeById('n1')?->capability->value);
    }

    private function arbiter(): ImageFileWorkArbitration
    {
        return new ImageFileWorkArbitration();
    }

    /**
     * @return array<string, mixed>
     */
    private function codeRunPlan(string $script = 'from PIL import Image'): array
    {
        return [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [[
                'id' => 'n1',
                'capability' => 'code_run',
                'depends_on' => [],
                'inputs' => [],
                'params' => ['script' => $script, 'image' => 'python'],
            ]],
        ];
    }

    private function png(string $text): Message
    {
        $file = $this->createStub(File::class);
        $file->method('getFileName')->willReturn('scene.png');
        $file->method('getFileType')->willReturn('png');

        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection([$file]));

        return $message;
    }
}

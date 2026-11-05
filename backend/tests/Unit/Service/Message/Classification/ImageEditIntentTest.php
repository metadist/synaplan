<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\Classification\ImageEditIntent;
use App\Service\Message\Classification\ImageEditKind;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageEditIntentTest extends TestCase
{
    #[DataProvider('kinds')]
    public function testKind(ImageEditKind $expected, string $text, array $files): void
    {
        self::assertSame($expected, $this->intent()->kind($this->message($text, $files), $text));
    }

    /**
     * @return iterable<string, array{ImageEditKind, string, list<string>}>
     */
    public static function kinds(): iterable
    {
        yield 'no file' => [ImageEditKind::None, 'make it wider and center the birds', []];
        yield 'describe' => [ImageEditKind::None, 'What is on this photo?', ['photo.png']];
        yield 'stamp' => [ImageEditKind::Programmatic, 'Stempel unten rechts INTERNAL drauf.', ['logo.png']];
        yield 'python' => [ImageEditKind::Programmatic, 'run a python script on this png', ['logo.png']];
        yield 'eva birds' => [ImageEditKind::Aesthetic, 'Kannst du das Bild so verändern, dass es etwas breiter wird und die Vögel mittig platziert sind?', ['scene.png']];
        yield 'center birds' => [ImageEditKind::Aesthetic, 'make it wider and center the birds', ['scene.png']];
        yield 'resize only' => [ImageEditKind::Ambiguous, 'Resize this image to half.', ['scene.png']];
        yield 'crop only' => [ImageEditKind::Ambiguous, 'Crop the image.', ['scene.png']];
        yield 'chart table' => [ImageEditKind::None, 'Mach daraus ein Balkendiagramm nach Region.', ['sales.csv']];
        yield 'what changed' => [ImageEditKind::None, 'Was ändert sich auf dem Bild?', ['scene.png']];
    }

    private function intent(): ImageEditIntent
    {
        return new ImageEditIntent();
    }

    /**
     * @param list<string> $files
     */
    private function message(string $text, array $files): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection(array_map(function (string $name): File {
            $file = $this->createStub(File::class);
            $file->method('getFileName')->willReturn($name);
            $file->method('getFileType')->willReturn(pathinfo($name, \PATHINFO_EXTENSION));

            return $file;
        }, $files)));

        return $message;
    }
}

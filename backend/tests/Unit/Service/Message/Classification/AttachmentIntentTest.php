<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Classification;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Document\DocumentToolsConfig;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Classification\AttachmentIntent;
use App\Service\Multitask\MultitaskRoutingConfig;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AttachmentIntentTest extends TestCase
{
    public function testADocumentAudioOrVideoGoesToFileAnalysis(): void
    {
        foreach (['report.pdf', 'voice.mp3', 'clip.mp4'] as $name) {
            self::assertSame(['analyzefile', 'file_analysis', true], $this->routeOf('What is in here?', [$name]), $name);
        }
    }

    public function testAPictureAloneLeavesTheDecisionToTheSorter(): void
    {
        self::assertNull($this->intent()->route($this->message('What is on this photo?', ['photo.jpg']), 'What is on this photo?'));
    }

    public function testAnOfficeEditGoesToOfficemakerOnlyWhereUploadEditsAreAllowed(): void
    {
        self::assertSame(['officemaker', 'document_generation', true], $this->routeOf('change the title', ['report.docx'], uploadEdits: true));
        self::assertSame(['analyzefile', 'file_analysis', true], $this->routeOf('change the title', ['report.docx']));
    }

    public function testAMergeOfSeveralFilesIsOneNodeOfTheMultiTaskEngine(): void
    {
        self::assertSame(['officemaker', 'document_combine', true], $this->routeOf('führe beide dateien in eine pdf zusammen', ['a.xlsx', 'b.pdf'], multitask: true));
        self::assertSame(['analyzefile', 'file_analysis', true], $this->routeOf('führe beide dateien in eine pdf zusammen', ['a.xlsx', 'b.pdf']), 'Without the engine the merge cannot run.');
        self::assertSame(['analyzefile', 'file_analysis', true], $this->routeOf('merge both and read it aloud', ['a.pdf', 'b.pdf'], multitask: true), 'A competing intent leaves it to the planner.');
    }

    public function testADemandToProduceAFileReachesTheSorter(): void
    {
        $text = 'Make a bar chart from this table, grouped by region.';

        self::assertNull($this->intent()->route($this->message($text, ['sales.csv']), $text));
        self::assertTrue($this->intent()->requestsFileProduction($this->message($text, ['sales.csv']), $text));
        self::assertFalse($this->intent()->requestsFileProduction($this->message($text, []), $text));
    }

    public function testCodeExecutionNeedsAFileARuntimeAndAnAction(): void
    {
        self::assertSame(
            [true, false, false],
            [
                $this->intent()->requestsCodeExecution($this->message('x', ['chart.png']), 'run python to count the red pixels'),
                $this->intent()->requestsCodeExecution($this->message('x', []), 'run python to count the red pixels'),
                $this->intent()->requestsCodeExecution($this->message('x', ['chart.png']), 'describe this image'),
            ],
        );
    }

    public function testAPictureIsRecognisedByItsGenericKindToo(): void
    {
        self::assertSame(
            [true, true, false],
            [
                $this->intent()->hasImageAttachment($this->message('x', ['photo.png'])),
                $this->intent()->hasImageAttachment($this->message('x', ['media_1_google.png'], kind: 'image')),
                $this->intent()->hasImageAttachment($this->message('x', ['report.pdf'])),
            ],
        );
    }

    /**
     * @param list<string> $files
     *
     * @return array{mixed, mixed, mixed}|null
     */
    private function routeOf(string $text, array $files, bool $uploadEdits = false, bool $multitask = false): ?array
    {
        $route = $this->intent($uploadEdits, $multitask)->route($this->message($text, $files), $text);

        return null === $route ? null : [$route['topic'], $route['intent'], $route['skip_sorting']];
    }

    private function intent(bool $uploadEdits = false, bool $multitask = false): AttachmentIntent
    {
        $converter = $this->createStub(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $documentTools = $this->createStub(DocumentToolsConfig::class);
        $documentTools->method('allowUploadEdit')->willReturn($uploadEdits);
        $routing = $this->createStub(MultitaskRoutingConfig::class);
        $routing->method('isRoutingEnabled')->willReturn($multitask);

        return new AttachmentIntent($this->createStub(EntityManagerInterface::class), new NullLogger(), $converter, $documentTools, $routing);
    }

    /**
     * @param list<string> $files
     */
    private function message(string $text, array $files, ?string $kind = null): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection(array_map(function (string $name) use ($kind): File {
            $file = $this->createStub(File::class);
            $file->method('getFileName')->willReturn($name);
            $file->method('getFileType')->willReturn($kind ?? pathinfo($name, \PATHINFO_EXTENSION));

            return $file;
        }, $files)));

        return $message;
    }
}

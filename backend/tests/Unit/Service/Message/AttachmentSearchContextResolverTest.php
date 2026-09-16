<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Service\Message\AttachmentSearchContextResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The resolver supplies the missing referent for attachment-referring search
 * turns: extracted file text first (free), a vision identification of the
 * image as fallback (one pic2text call), null when neither exists — in which
 * case MessageProcessor drops a purely vote-triggered search.
 */
final class AttachmentSearchContextResolverTest extends TestCase
{
    public function testReturnsNullWithoutAttachments(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('analyzeImage');

        $message = (new Message())->setText('what is that?');

        self::assertNull($this->resolver($aiFacade)->resolve($message, 1));
    }

    public function testPrefersExtractedFileTextOverVision(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('analyzeImage');

        $file = (new File())
            ->setFileName('contract.pdf')
            ->setFilePath('1/contract.pdf')
            ->setFileType('pdf')
            ->setFileText('GENERAL DATA PROTECTION REGULATION (EU) 2016/679 — Article 17');

        $message = (new Message())->setText('is this still valid?');
        $message->addFile($file);

        self::assertSame(
            'GENERAL DATA PROTECTION REGULATION (EU) 2016/679 — Article 17',
            $this->resolver($aiFacade)->resolve($message, 1),
        );
    }

    public function testClipsLongFileText(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('analyzeImage');

        $file = (new File())
            ->setFileName('report.pdf')
            ->setFilePath('1/report.pdf')
            ->setFileType('pdf')
            ->setFileText(str_repeat('a', 5000));

        $message = (new Message())->setText('summarize and check if current');
        $message->addFile($file);

        $context = $this->resolver($aiFacade)->resolve($message, 1);

        self::assertNotNull($context);
        self::assertSame(1501, mb_strlen($context), 'clipped to 1500 chars + ellipsis');
        self::assertStringEndsWith('…', $context);
    }

    public function testFallsBackToVisionIdentificationForTextlessImage(): void
    {
        // A product photo without visible text: the preprocess OCR pass
        // stored '' — only a vision identification can name the subject.
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('analyzeImage')
            ->with(
                '1/photo.jpg',
                $this->stringContains('Identify what is shown'),
                42,
            )
            ->willReturn(['content' => 'Sony WH-1000XM6 wireless headphones, black', 'provider' => 'test']);

        $file = (new File())
            ->setFileName('photo.jpg')
            ->setFilePath('1/photo.jpg')
            ->setFileType('jpg')
            ->setFileText('');

        $message = (new Message())->setText('how much does this cost?');
        $message->addFile($file);

        self::assertSame(
            'Sony WH-1000XM6 wireless headphones, black',
            $this->resolver($aiFacade)->resolve($message, 42),
        );
    }

    public function testReturnsNullWhenVisionFails(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('analyzeImage')
            ->willThrowException(new \RuntimeException('no vision model'));

        $file = (new File())
            ->setFileName('photo.png')
            ->setFilePath('1/photo.png')
            ->setFileType('png')
            ->setFileText('');

        $message = (new Message())->setText('what is that?');
        $message->addFile($file);

        self::assertNull($this->resolver($aiFacade)->resolve($message, 1));
    }

    public function testReturnsNullWhenVisionReturnsNothing(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(['content' => '   ']);

        $file = (new File())
            ->setFileName('photo.png')
            ->setFilePath('1/photo.png')
            ->setFileType('png')
            ->setFileText('');

        $message = (new Message())->setText('what is that?');
        $message->addFile($file);

        self::assertNull($this->resolver($aiFacade)->resolve($message, 1));
    }

    public function testDoesNotCallVisionForTextlessNonImageFile(): void
    {
        // A PDF whose extraction failed has no text AND no image to identify.
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('analyzeImage');

        $file = (new File())
            ->setFileName('scan.pdf')
            ->setFilePath('1/scan.pdf')
            ->setFileType('pdf')
            ->setFileText('');

        $message = (new Message())->setText('is this correct?');
        $message->addFile($file);

        self::assertNull($this->resolver($aiFacade)->resolve($message, 1));
    }

    public function testResolvesLegacySingleFileImage(): void
    {
        // Channel messages (WhatsApp) still use the legacy single-file columns.
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('analyzeImage')
            ->with('5/wa-image.jpeg', $this->anything(), 7)
            ->willReturn(['content' => 'Eiffel Tower in Paris at dusk']);

        $message = (new Message())
            ->setText('what is that?')
            ->setFile(123)
            ->setFilePath('5/wa-image.jpeg')
            ->setFileType('jpeg');

        self::assertSame(
            'Eiffel Tower in Paris at dusk',
            $this->resolver($aiFacade)->resolve($message, 7),
        );
    }

    private function resolver(AiFacade $aiFacade): AttachmentSearchContextResolver
    {
        return new AttachmentSearchContextResolver($aiFacade, new NullLogger());
    }
}

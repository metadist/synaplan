<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\ExtractionQualityGate;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugConfigService;
use App\Service\File\TextCleaner;
use PHPUnit\Framework\TestCase;

final class ExtractionQualityGateTest extends TestCase
{
    public function testEmptyResultFails(): void
    {
        $verdict = $this->gate()->verdict(
            ExtractionResult::of('', 'tika'),
            $this->pdfRequest(),
        );

        self::assertFalse($verdict->passed);
        self::assertSame('empty', $verdict->reason);
    }

    public function testNonPdfFamilyIsNotApplicable(): void
    {
        $text = 'Short but fine for a markdown file.';
        $verdict = $this->gate()->verdict(
            ExtractionResult::of($text, 'native'),
            new ExtractionRequest('/tmp/a.md', 'a.md', 'text/markdown', 'md', 1, false, 'text'),
        );

        self::assertTrue($verdict->passed);
        self::assertSame('not_applicable', $verdict->reason);
    }

    public function testPdfVerdictMatchesTextCleanerIsLowQuality(): void
    {
        $cleaner = new TextCleaner();
        $gate = $this->gate($cleaner);

        $good = 'The quarterly revenue for EMEA was 4.2 million EUR in Q3 2025.';
        $bad = 'aaaaaaa';

        self::assertFalse($cleaner->isLowQuality($good, 10, 3.0));
        self::assertTrue($cleaner->isLowQuality($bad, 10, 3.0));

        $goodVerdict = $gate->verdict(ExtractionResult::of($good, 'tika'), $this->pdfRequest());
        $badVerdict = $gate->verdict(ExtractionResult::of($bad, 'tika'), $this->pdfRequest());

        self::assertTrue($goodVerdict->passed);
        self::assertSame('quality_ok', $goodVerdict->reason);
        self::assertFalse($badVerdict->passed);
        self::assertSame('low_quality', $badVerdict->reason);
    }

    public function testMarkdownTablePassesEvenWhenEntropyIsLow(): void
    {
        $markdown = "# Report\n\n| A | A | A |\n| --- | --- | --- |\n| A | A | A |\n";
        $verdict = $this->gate()->verdict(
            ExtractionResult::of('AAAAAAA', 'docling', [], $markdown),
            $this->pdfRequest(),
        );

        self::assertTrue($verdict->passed);
        self::assertSame('markdown_structure', $verdict->reason);
    }

    private function gate(?TextCleaner $cleaner = null): ExtractionQualityGate
    {
        $config = $this->createMock(PlugConfigService::class);
        $config->method('qualityApplyTo')->willReturn(['pdf']);
        $config->method('qualityMinLength')->willReturn(10);
        $config->method('qualityMinEntropy')->willReturn(3.0);

        return new ExtractionQualityGate($config, $cleaner ?? new TextCleaner());
    }

    private function pdfRequest(): ExtractionRequest
    {
        return new ExtractionRequest('/tmp/a.pdf', 'a.pdf', 'application/pdf', 'pdf', 1, false, 'document');
    }
}

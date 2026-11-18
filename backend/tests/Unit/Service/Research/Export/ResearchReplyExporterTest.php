<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Export;

use App\Entity\Message;
use App\Entity\SearchResult;
use App\Repository\SearchResultRepository;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\Presentation\PptxRenderer;
use App\Service\File\Presentation\SlideMarkdownParser;
use App\Service\Research\Export\ReplyExportFormat;
use App\Service\Research\Export\ResearchReplyExporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResearchReplyExporterTest extends TestCase
{
    private const TABLE = "| Pump | Cost |\n| --- | --- |\n| Air | 12000 |\n| Ground | 25000 |";

    public function testTheQuestionIsTheTitleWhenTheReplyHasNoTopHeading(): void
    {
        $file = $this->exporter("Long question\nwith a second line", [])->export($this->reply('Short answer.'), ReplyExportFormat::Markdown, 'en');

        self::assertSame("# Long question\n\nShort answer.\n", $file->content);
        self::assertSame('long-question.md', $file->fileName);
        self::assertSame('text/markdown; charset=utf-8', $file->contentType);
    }

    public function testTheReplysOwnTopHeadingIsTheTitleAndIsNotRepeated(): void
    {
        $file = $this->exporter('The question', [])->export($this->reply("# Heat **pumps** compared\n\nBody."), ReplyExportFormat::Markdown, 'en');

        self::assertSame("# Heat **pumps** compared\n\nBody.\n", $file->content);
        self::assertSame('heat-pumps-compared.md', $file->fileName);
    }

    public function testWithoutAQuestionTheTitleIsTheTranslatedFallback(): void
    {
        $file = $this->exporter(null, [])->export($this->reply('Body.'), ReplyExportFormat::Markdown, 'de');

        self::assertStringStartsWith('# research.report.untitled@de', $file->content);
    }

    public function testTheSourcesAreListedWithNumbersAndLinks(): void
    {
        $file = $this->exporter('Q', [
            $this->source('Guide', 'https://example.org/guide', 'example.org'),
            $this->source('', 'https://example.com/x', 'example.com'),
        ])->export($this->reply('Answer [1][2].'), ReplyExportFormat::Markdown, 'en');

        self::assertStringEndsWith(
            "## research.report.sources@en\n\n1. Guide — https://example.org/guide\n2. example.com — https://example.com/x\n",
            $file->content,
        );
    }

    public function testCombiningMarksStayInsideTheirWordInTheFileName(): void
    {
        $file = $this->exporter('हिंदी में वीज़ा', [])->export($this->reply('Body.'), ReplyExportFormat::Markdown, 'hi');

        self::assertSame('हिंदी-में-वीज़ा.md', $file->fileName);
    }

    public function testAUnicodeTitleKeepsItsLettersAndGetsAnAsciiFallback(): void
    {
        $file = $this->exporter('Wärmepumpe für ein Altbau-Haus?', [])->export($this->reply('Ja.'), ReplyExportFormat::Html, 'de');

        self::assertSame('wärmepumpe-für-ein-altbau-haus.html', $file->fileName);
        self::assertSame('waermepumpe-fuer-ein-altbau-haus.html', $file->asciiFileName);
    }

    public function testAHostileTitleAndRawHtmlAreEscapedInTheWebPage(): void
    {
        $file = $this->exporter('<script>alert(1)</script> question', [
            $this->source('<img src=x onerror=alert(2)>', 'javascript:alert(3)', 'evil'),
        ])->export($this->reply("Text <iframe src=\"https://evil.test\"></iframe> and ![pixel](https://tracker.test/p.png)\n\n".self::TABLE), ReplyExportFormat::Html, 'en');
        $html = $file->content;

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<title>&lt;script&gt;alert(1)&lt;/script&gt; question</title>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('<a href="https://tracker.test/p.png">pixel</a>', $html);
        self::assertStringContainsString("Content-Security-Policy\" content=\"default-src 'none'", $html);
        self::assertStringContainsString('<td>Ground</td>', $html);
        self::assertSame('script-alert-1-script-question.html', $file->fileName);
    }

    public function testTheWordDocumentKeepsTheTableAndLoadsNoImage(): void
    {
        $file = $this->exporter('Which pump?', [$this->source('Guide', 'https://example.org/guide', 'example.org')])
            ->export($this->reply("Intro <b>raw</b> ![x](/etc/passwd)\n\n".self::TABLE), ReplyExportFormat::Docx, 'en');

        self::assertSame('which-pump.docx', $file->fileName);
        $xml = $this->documentXml($file->content);
        self::assertStringContainsString('<w:tbl>', $xml);
        self::assertStringContainsString('Ground', $xml);
        self::assertStringContainsString('&lt;b&gt;raw&lt;/b&gt;', $xml);
        self::assertStringContainsString('Guide — https://example.org/guide', strip_tags($xml));
        self::assertStringNotContainsString('<w:drawing>', $xml);
        self::assertStringNotContainsString('<w:pict>', $xml);
    }

    public function testCodeKeepsItsCharacters(): void
    {
        $file = $this->exporter('Q', [])->export($this->reply("Use `a<b` here.\n\n```\nif (x < 1) { ![y](z) }\n```"), ReplyExportFormat::Html, 'en');

        self::assertStringContainsString('<code>a&lt;b</code>', $file->content);
        self::assertStringContainsString('if (x &lt; 1) { ![y](z) }', $file->content);
    }

    /**
     * @param list<SearchResult> $sources
     */
    private function exporter(?string $question, array $sources): ResearchReplyExporter
    {
        $questionMessage = null === $question ? null : (new Message())->setText($question);
        $repository = $this->createStub(SearchResultRepository::class);
        $repository->method('findQuestionFor')->willReturn($questionMessage);
        $repository->method('findByMessage')->willReturn($sources);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => $id.'@'.$locale,
        );
        $logger = new NullLogger();

        return new ResearchReplyExporter(
            $repository,
            new DocumentGeneratorService($logger, new SlideMarkdownParser(), new PptxRenderer($logger)),
            $translator,
        );
    }

    private function reply(string $text): Message
    {
        return (new Message())->setText($text)->setDirection('OUT');
    }

    private function source(string $title, string $url, string $host): SearchResult
    {
        return (new SearchResult())->setTitle($title)->setUrl($url)->setSource($host);
    }

    private function documentXml(string $docx): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx-test-');
        try {
            file_put_contents($path, $docx);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            return $xml;
        } finally {
            @unlink($path);
        }
    }
}

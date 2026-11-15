<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document;

use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\ProvidedWordBody;
use App\Service\Document\Render\DocxRenderer;
use App\Service\Document\Tool\DocumentSession;
use PHPUnit\Framework\TestCase;

final class ProvidedWordBodyTest extends TestCase
{
    private const PLAN = <<<'MD'
        # Reiseplan: Rio de Janeiro – Nova Odessa – Copacabana

        ## Überblick

        - **Start:** Flug nach Rio de Janeiro am 12.
        - **Transport:** Mietwagen von Rio nach Nova Odessa und zurück
        - **Rückfahrt:** Ein Sightseeing-Stopp
        - **Abschluss:** 2–3 Nächte in einem Luxushotel an der Copacabana

        Die Dauer des Aufenthalts in Nova Odessa ist hier nicht festgelegt.

        ## Tag 1 (der 12.): Ankunft in Rio

        - Landung am internationalen Flughafen Rio de Janeiro–Galeão (GIG).

        | Hotel | Lage |
        | ----- | ---- |
        | Copacabana Palace | Avenida Atlântica |
        MD;

    public function testRestoresTheBodyWhenTheFileOnlyHasTheTitle(): void
    {
        $session = $this->titleOnlySession();

        self::assertTrue(ProvidedWordBody::restore($session, $this->request(self::PLAN)));

        $text = (new DocumentTextProjector())->project($session->model);
        self::assertStringContainsString('Überblick', $text);
        self::assertStringContainsString('• Start: Flug nach Rio de Janeiro am 12.', $text);
        self::assertStringContainsString('Copacabana Palace', $text);
        self::assertStringNotContainsString('**', $text);
    }

    public function testSecondRestoreLeavesTheRecoveredDocumentAlone(): void
    {
        $session = $this->titleOnlySession();
        self::assertTrue(ProvidedWordBody::restore($session, $this->request(self::PLAN)));

        self::assertFalse(ProvidedWordBody::restore($session, $this->request(self::PLAN)));
    }

    public function testARequestToShortenIsNotExpandedBackToTheSource(): void
    {
        $session = $this->titleOnlySession();
        $prompt = "Summarize the plan into a Word document:\n".self::PLAN;

        self::assertFalse(ProvidedWordBody::restore($session, $this->request($prompt)));
        $word = $session->word();
        self::assertInstanceOf(WordModel::class, $word);
        self::assertCount(1, $word->blocks);
    }

    public function testRenderedDocumentContainsTheBodyNotOnlyTheHeading(): void
    {
        $session = $this->titleOnlySession();
        self::assertTrue(ProvidedWordBody::restore($session, $this->request(self::PLAN)));

        $path = sys_get_temp_dir().'/provided-word-body-'.uniqid('', true).'.docx';
        (new DocxRenderer())->render($session->model, $path);

        $xml = $this->documentXml($path);
        @unlink($path);

        self::assertStringContainsString('Flug nach Rio de Janeiro am 12.', $xml);
        self::assertStringContainsString('Heading2', $xml);
        self::assertStringContainsString('Copacabana Palace', $xml);
        self::assertStringNotContainsString('**Start:**', $xml);
    }

    public function testChannelEnvelopeIsUnwrapped(): void
    {
        $session = $this->titleOnlySession();
        $envelope = json_encode([
            'BTEXT' => "Create a Word document containing the following text:\n".self::PLAN,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertTrue(ProvidedWordBody::restore($session, $envelope));
        self::assertStringContainsString(
            'Überblick',
            (new DocumentTextProjector())->project($session->model),
        );
    }

    public function testScaffoldingAfterThePlanIsNotCopiedIntoTheFile(): void
    {
        $session = $this->titleOnlySession();
        $noise = self::PLAN
            ."\n\nThe file MUST be a .docx file (the user explicitly asked for this format)."
            ."\n\nFollow these assistant instructions for the file content. Still answer with the file envelope only.\nBe brief."
            ."\n\n\n---\n\n\nUser provided 1 file(s):\n\n# Attachment\n\nHuge reference material that must stay out of the document.";

        self::assertTrue(ProvidedWordBody::restore($session, $this->request($noise)));

        $text = (new DocumentTextProjector())->project($session->model);
        self::assertStringContainsString('Überblick', $text);
        self::assertStringNotContainsString('The file MUST', $text);
        self::assertStringNotContainsString('file envelope only', $text);
        self::assertStringNotContainsString('Huge reference', $text);
    }

    public function testAFinishedPlanIsNotReplacedBecauseAReferenceIsLonger(): void
    {
        $session = $this->titleOnlySession();
        self::assertTrue(ProvidedWordBody::restore($session, $this->request(self::PLAN)));
        $kept = (new DocumentTextProjector())->project($session->model);

        $polluted = $this->request(self::PLAN)
            ."\n\n\n---\n\n\nUser provided 1 file(s):\n\n".str_repeat('Huge reference material. ', 80);

        self::assertFalse(ProvidedWordBody::restore($session, $polluted));
        self::assertSame($kept, (new DocumentTextProjector())->project($session->model));
    }

    public function testAShorteningInstructionAfterThePlanIsKept(): void
    {
        $session = $this->titleOnlySession();
        $prompt = self::PLAN."\n\nSummarize this into a one-paragraph Word document.";

        self::assertFalse(ProvidedWordBody::restore($session, $this->request($prompt)));
        $word = $session->word();
        self::assertInstanceOf(WordModel::class, $word);
        self::assertCount(1, $word->blocks);
    }

    public function testASeparatedGermanSummaryInstructionIsKept(): void
    {
        $session = $this->titleOnlySession();
        $prompt = "Fasse den folgenden Plan zusammen und erstelle ein Word-Dokument.\n".self::PLAN;

        self::assertFalse(ProvidedWordBody::restore($session, $this->request($prompt)));
        $word = $session->word();
        self::assertInstanceOf(WordModel::class, $word);
        self::assertCount(1, $word->blocks);
    }

    public function testEmptyEdgeCellsKeepTheirColumn(): void
    {
        $session = $this->titleOnlySession();
        $table = "# Hotels\n\n".str_repeat('A note about the stay that pads the body. ', 12)."\n\n"
            ."| | Travel note | |\n| --- | --- | --- |\n| | Pack light | |\n";

        self::assertTrue(ProvidedWordBody::restore($session, $table));

        $rows = null;
        $word = $session->word();
        self::assertInstanceOf(WordModel::class, $word);
        foreach ($word->blocks as $block) {
            if (WordBlock::TYPE_TABLE === $block->type) {
                $rows = $block->payload['rows'];
            }
        }
        self::assertSame([
            ['', 'Travel note', ''],
            ['', 'Pack light', ''],
        ], $rows);
    }

    public function testMarkdownLinksKeepTheirDestination(): void
    {
        $session = $this->titleOnlySession();
        $body = "# Booking\n\n".str_repeat('Reserve the room before leaving. ', 12)
            ."\n\nSee [Book hotel](https://example.com/booking) today.\n";

        self::assertTrue(ProvidedWordBody::restore($session, $body));

        $text = (new DocumentTextProjector())->project($session->model);
        self::assertStringContainsString('Book hotel (https://example.com/booking)', $text);

        $path = sys_get_temp_dir().'/provided-word-link-'.uniqid('', true).'.docx';
        (new DocxRenderer())->render($session->model, $path);
        $xml = $this->documentXml($path);
        @unlink($path);
        self::assertStringContainsString('https://example.com/booking', $xml);
    }

    private function titleOnlySession(): DocumentSession
    {
        $session = DocumentSession::empty('docx', 'document.docx');
        $word = $session->word();
        self::assertInstanceOf(WordModel::class, $word);
        $word->blocks[] = new WordBlock('only-title', WordBlock::TYPE_HEADING, [
            'text' => 'Reiseplan: Rio de Janeiro – Nova Odessa – Copacabana',
            'level' => 1,
        ]);

        return $session;
    }

    private function request(string $prompt): string
    {
        return "Create a Word document (DOCX) containing the following German text:\n".$prompt;
    }

    private function documentXml(string $path): string
    {
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        self::assertIsString($xml);

        return $xml;
    }
}

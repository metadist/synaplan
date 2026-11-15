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

        self::assertTrue(ProvidedWordBody::restore($session, [
            ['role' => 'user', 'content' => $envelope],
        ]));
        self::assertStringContainsString(
            'Überblick',
            (new DocumentTextProjector())->project($session->model),
        );
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

    /**
     * @return list<array<string, mixed>>
     */
    private function request(string $prompt): array
    {
        return [
            ['role' => 'system', 'content' => 'tools'],
            ['role' => 'user', 'content' => "Create a Word document (DOCX) containing the following German text:\n".$prompt],
        ];
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

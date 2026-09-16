<?php

declare(strict_types=1);

namespace App\Service\Document\Import;

use App\Service\Document\Model\DeckModel;

/**
 * Best-effort PPTX import: slide titles from slide XML. Layout is not preserved.
 */
final class DeckImporter
{
    /**
     * @return array{model: DeckModel, report: ImportFidelityReport}
     */
    public function import(string $absolutePath): array
    {
        $slides = [];
        $notes = ['Slide layout, images and speaker notes are not imported.'];
        if (!class_exists(\ZipArchive::class) || !is_file($absolutePath)) {
            return [
                'model' => DeckModel::empty(),
                'report' => ImportFidelityReport::lossy(['Could not open the presentation.']),
            ];
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($absolutePath)) {
            return [
                'model' => DeckModel::empty(),
                'report' => ImportFidelityReport::lossy(['Could not open the presentation ZIP.']),
            ];
        }
        for ($i = 1; $i < 200; ++$i) {
            $xml = $zip->getFromName(sprintf('ppt/slides/slide%d.xml', $i));
            if (false === $xml) {
                break;
            }
            $title = $this->firstText($xml);
            $bullets = $this->allTexts($xml);
            if ([] !== $bullets && $bullets[0] === $title) {
                array_shift($bullets);
            }
            $slides[] = [
                'title' => $title,
                'bullets' => $bullets,
                'titleSlide' => 1 === $i,
                'imageReferences' => [],
            ];
        }
        $zip->close();

        return [
            'model' => new DeckModel($slides),
            'report' => ImportFidelityReport::lossy($notes),
        ];
    }

    private function firstText(string $xml): string
    {
        if (preg_match('/<a:t[^>]*>([^<]+)<\/a:t>/', $xml, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function allTexts(string $xml): array
    {
        preg_match_all('/<a:t[^>]*>([^<]+)<\/a:t>/', $xml, $matches);
        $out = [];
        foreach ($matches[1] as $text) {
            $out[] = html_entity_decode($text, ENT_QUOTES | ENT_XML1);
        }

        return $out;
    }
}

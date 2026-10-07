<?php

declare(strict_types=1);

namespace App\Service\Chat;

/**
 * A single-page PDF of plain text. No external library: the chat export
 * only needs a file a browser can open.
 */
final class PlainTextPdf
{
    public function render(string $title, string $body): string
    {
        $text = $this->escape($title)."\n\n".$this->escape($body);
        $text = substr($text, 0, 4000);
        $stream = 'BT /F1 11 Tf 48 780 Td 14 TL ('.$this->pdfString($text).') Tj ET';
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            '4 0 obj << /Length '.strlen($stream)." >> stream\n".$stream."\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; ++$i) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function escape(string $text): string
    {
        $clean = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? $text;

        return str_replace(["\r", "\n"], ['', ' '], $clean);
    }

    private function pdfString(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (false === $ascii) {
            $ascii = $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }
}

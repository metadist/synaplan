<?php

declare(strict_types=1);

namespace App\Service\Chat;

/**
 * A multi-page PDF of plain text. No external library: the chat export
 * only needs a file a browser can open. Lines wrap, and a transcript that
 * does not fit says so on the last page instead of disappearing.
 */
final class PlainTextPdf
{
    private const PAGE_WIDTH = 612;
    private const PAGE_HEIGHT = 792;
    private const MARGIN = 48;
    private const FONT_SIZE = 11;
    private const LINE_HEIGHT = 14;
    private const LINES_PER_PAGE = 48;
    private const MAX_PAGES = 40;

    public function render(string $title, string $body): string
    {
        $lines = $this->wrap($title);
        $lines[] = '';
        foreach ($this->wrap($body) as $line) {
            $lines[] = $line;
        }
        $pages = array_chunk($lines, self::LINES_PER_PAGE);
        if (count($pages) > self::MAX_PAGES) {
            $pages = array_slice($pages, 0, self::MAX_PAGES);
            $last = count($pages) - 1;
            $pages[$last][count($pages[$last]) - 1] = 'The rest of this chat is not in this file.';
        }

        return $this->document($pages);
    }

    /**
     * @param list<list<string>> $pages
     */
    private function document(array $pages): string
    {
        $pageCount = count($pages);
        $objects = [];
        $objects[] = '';
        $kids = [];
        $fontId = 3 + ($pageCount * 2);
        for ($i = 0; $i < $pageCount; ++$i) {
            $pageId = 3 + ($i * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
                $fontId,
            );
            $stream = $this->stream($pages[$i]);
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pagesObject = '<< /Type /Pages /Count '.$pageCount.' /Kids ['.implode(' ', $kids).'] >>';

        $offsets = [0, strlen("%PDF-1.4\n")];
        $pdf = "%PDF-1.4\n";
        $pdf .= '1 0 obj '.$objects[0]." endobj\n";
        $offsets[2] = strlen($pdf);
        $pdf .= '2 0 obj '.$pagesObject." endobj\n";
        $n = 3;
        for ($i = 1; $i < count($objects); ++$i) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n.' 0 obj '.$objects[$i]." endobj\n";
            ++$n;
        }
        $size = $n;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".$size."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $size; ++$i) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size '.$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    /**
     * @param list<string> $lines
     */
    private function stream(array $lines): string
    {
        $y = self::PAGE_HEIGHT - self::MARGIN;
        $ops = 'BT /F1 '.self::FONT_SIZE.' Tf '.self::MARGIN.' '.$y.' Td '.self::LINE_HEIGHT." TL\n";
        $first = true;
        foreach ($lines as $line) {
            if (!$first) {
                $ops .= "T*\n";
            }
            $first = false;
            $ops .= '('.$this->pdfString($line).") Tj\n";
        }
        $ops .= 'ET';

        return $ops;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $width = self::PAGE_WIDTH - (self::MARGIN * 2);
        $maxChars = max(20, (int) floor($width / (self::FONT_SIZE * 0.5)));
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $paragraph = $this->ascii($paragraph);
            if ('' === $paragraph) {
                $lines[] = '';
                continue;
            }
            while (strlen($paragraph) > $maxChars) {
                $break = strrpos(substr($paragraph, 0, $maxChars + 1), ' ');
                if (false === $break || $break < 8) {
                    $break = $maxChars;
                }
                $lines[] = rtrim(substr($paragraph, 0, $break));
                $paragraph = ltrim(substr($paragraph, $break));
            }
            $lines[] = $paragraph;
        }

        return $lines;
    }

    private function ascii(string $text): string
    {
        $clean = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? $text;
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);

        return false === $ascii ? $clean : $ascii;
    }

    private function pdfString(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Psr\Log\LoggerInterface;

/**
 * Sheet-by-sheet / slide-by-slide Markdown for RAG and file analysis.
 *
 * Spreadsheets keep A1 coordinates so answers can cite {@code Sheet1!B12}.
 * Decks keep slide titles, body text and speaker notes. Never throws.
 */
final readonly class StructuredTextExtractor
{
    public const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv'];
    public const DECK_EXTENSIONS = ['pptx'];

    public function __construct(
        private LoggerInterface $logger,
        private int $officeTextMaxRows = 500,
    ) {
    }

    public function supports(string $extension): bool
    {
        $ext = strtolower($extension);

        return in_array($ext, self::SPREADSHEET_EXTENSIONS, true)
            || in_array($ext, self::DECK_EXTENSIONS, true);
    }

    public function extract(string $absolutePath, string $extension): ?string
    {
        if (!is_file($absolutePath) || !$this->supports($extension)) {
            return null;
        }

        try {
            $ext = strtolower($extension);
            if (in_array($ext, self::SPREADSHEET_EXTENSIONS, true)) {
                return $this->extractSpreadsheet($absolutePath, $ext);
            }

            return $this->extractDeck($absolutePath);
        } catch (\Throwable $e) {
            $this->logger->warning('StructuredTextExtractor failed', [
                'file' => basename($absolutePath),
                'ext' => $extension,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractSpreadsheet(string $absolutePath, string $extension): string
    {
        $reader = match ($extension) {
            'csv' => SpreadsheetIOFactory::createReader('Csv'),
            'xls' => SpreadsheetIOFactory::createReader('Xls'),
            'ods' => SpreadsheetIOFactory::createReader('Ods'),
            default => SpreadsheetIOFactory::createReader('Xlsx'),
        };
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

        $parts = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = trim($sheet->getTitle());
            $title = '' !== $title ? $title : 'Sheet';
            $highestRow = (int) $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColIndex = Coordinate::columnIndexFromString($highestColumn);
            if ($highestRow < 1 || $highestColIndex < 1) {
                continue;
            }

            $maxRows = max(1, $this->officeTextMaxRows);
            $usedRows = min($highestRow, $maxRows);
            $header = [' '];
            for ($col = 1; $col <= $highestColIndex; ++$col) {
                $header[] = Coordinate::stringFromColumnIndex($col);
            }

            $lines = [
                '## '.$title,
                '',
                '| '.implode(' | ', $header).' |',
                '| '.implode(' | ', array_fill(0, count($header), '---')).' |',
            ];

            for ($row = 1; $row <= $usedRows; ++$row) {
                $cells = [(string) $row];
                for ($col = 1; $col <= $highestColIndex; ++$col) {
                    $coord = Coordinate::stringFromColumnIndex($col).$row;
                    $cells[] = $this->formatCell($sheet->getCell($coord)->getValue(), $this->calculated($sheet, $coord));
                }
                $lines[] = '| '.implode(' | ', $cells).' |';
            }

            if ($highestRow > $usedRows) {
                $lines[] = '';
                $lines[] = '_'.($highestRow - $usedRows).' more rows_';
            }

            $parts[] = implode("\n", $lines);
        }
        $spreadsheet->disconnectWorksheets();

        return implode("\n\n", $parts);
    }

    private function calculated(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $coord): mixed
    {
        try {
            return $sheet->getCell($coord)->getCalculatedValue();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatCell(mixed $raw, mixed $calculated): string
    {
        $rawText = $this->cellToString($raw);
        $calcText = $this->cellToString($calculated);
        if (str_starts_with($rawText, '=') && '' !== $calcText && $calcText !== $rawText) {
            return $this->escapeCell($rawText.' → '.$calcText);
        }

        return $this->escapeCell($rawText);
    }

    private function cellToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (null === $value) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function escapeCell(string $value): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $value);
    }

    private function extractDeck(string $absolutePath): string
    {
        $reader = PresentationIOFactory::createReader('PowerPoint2007');
        $presentation = $reader->load($absolutePath);
        $parts = [];
        $index = 0;
        foreach ($presentation->getAllSlides() as $slide) {
            ++$index;
            $texts = $this->slideTexts($slide);
            $title = array_shift($texts) ?? 'Untitled';
            $body = array_values(array_filter($texts, static fn (string $line): bool => '' !== trim($line)));
            $notes = $this->slideNotes($slide);

            $block = ['## Slide '.$index.' — '.$title, ''];
            foreach ($body as $line) {
                $block[] = $line;
            }
            if ('' !== $notes) {
                $block[] = '';
                $block[] = '_Notes:_ '.$notes;
            }
            $parts[] = implode("\n", $block);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function slideTexts(Slide $slide): array
    {
        $lines = [];
        foreach ($slide->getShapeCollection() as $shape) {
            if (!$shape instanceof RichText) {
                continue;
            }
            $text = trim($shape->getPlainText());
            if ('' === $text) {
                continue;
            }
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $line = trim($line);
                if ('' !== $line) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    private function slideNotes(Slide $slide): string
    {
        $note = $slide->getNote();
        $chunks = [];
        foreach ($note->getShapeCollection() as $shape) {
            if ($shape instanceof RichText) {
                $text = trim($shape->getPlainText());
                if ('' !== $text) {
                    $chunks[] = $text;
                }
            }
        }

        return implode(' ', $chunks);
    }
}

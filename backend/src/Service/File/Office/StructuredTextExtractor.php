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

    /** Sheets with more rows than this get a `### Profile` block above the table. */
    public const PROFILE_MIN_ROWS = 20;

    /** Upper bound of rows scanned for the profile (keeps pathological sheets bounded). */
    private const PROFILE_MAX_ROWS = 200000;

    /** Rows read per block while profiling. */
    private const PROFILE_BLOCK_ROWS = 2000;

    /** Distinct text values tracked per column before the column counts as "many". */
    private const PROFILE_DISTINCT_CAP = 200;

    /** Most frequent text values listed per column. */
    private const PROFILE_TOP_VALUES = 4;

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

            $lines = ['## '.$title, ''];

            if ($highestRow > $usedRows || $highestRow > self::PROFILE_MIN_ROWS) {
                foreach ($this->profileLines($sheet, $highestRow, $highestColIndex, $usedRows) as $line) {
                    $lines[] = $line;
                }
                $lines[] = '';
            }

            $lines[] = '| '.implode(' | ', $header).' |';
            $lines[] = '| '.implode(' | ', array_fill(0, count($header), '---')).' |';

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

    /**
     * All-row column statistics. The Markdown table below it is capped at
     * `officeTextMaxRows`, so without this block an analytics question about a
     * 5 000-row sheet is answered from the first 500 rows and silently wrong.
     * The profile is computed over EVERY row (bounded by PROFILE_MAX_ROWS) so
     * totals, ranges and category counts are true for the whole sheet.
     *
     * @return list<string>
     */
    private function profileLines(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $highestRow, int $highestColIndex, int $usedRows): array
    {
        $scanRows = min($highestRow, self::PROFILE_MAX_ROWS);
        $highestColumn = Coordinate::stringFromColumnIndex($highestColIndex);

        /** @var array<int, array{label: string, nonEmpty: int, numeric: int, min: ?float, max: ?float, sum: float, distinct: array<string, int>, distinctOverflow: bool}> $columns */
        $columns = [];
        for ($col = 1; $col <= $highestColIndex; ++$col) {
            $columns[$col] = ['label' => '', 'nonEmpty' => 0, 'numeric' => 0, 'min' => null, 'max' => null, 'sum' => 0.0, 'distinct' => [], 'distinctOverflow' => false];
        }

        for ($start = 1; $start <= $scanRows; $start += self::PROFILE_BLOCK_ROWS) {
            $end = min($scanRows, $start + self::PROFILE_BLOCK_ROWS - 1);
            $block = $this->rangeValues($sheet, sprintf('A%d:%s%d', $start, $highestColumn, $end));

            foreach ($block as $offset => $row) {
                $rowNumber = $start + $offset;
                foreach ($row as $index => $value) {
                    $col = $index + 1;
                    if (!isset($columns[$col])) {
                        continue;
                    }
                    $text = $this->cellToString($value);
                    if (1 === $rowNumber) {
                        // Header row: a non-numeric label names the column.
                        if ('' !== $text && !is_numeric($text)) {
                            $columns[$col]['label'] = $text;
                            continue;
                        }
                    }
                    if ('' === $text) {
                        continue;
                    }
                    ++$columns[$col]['nonEmpty'];
                    if (is_numeric($text)) {
                        $number = (float) $text;
                        ++$columns[$col]['numeric'];
                        $columns[$col]['sum'] += $number;
                        $columns[$col]['min'] = null === $columns[$col]['min'] ? $number : min($columns[$col]['min'], $number);
                        $columns[$col]['max'] = null === $columns[$col]['max'] ? $number : max($columns[$col]['max'], $number);
                        continue;
                    }
                    if (isset($columns[$col]['distinct'][$text])) {
                        ++$columns[$col]['distinct'][$text];
                    } elseif (count($columns[$col]['distinct']) < self::PROFILE_DISTINCT_CAP) {
                        $columns[$col]['distinct'][$text] = 1;
                    } else {
                        $columns[$col]['distinctOverflow'] = true;
                    }
                }
            }
        }

        $lines = [
            '### Profile',
            sprintf(
                '- Rows: %s (row 1 = header) · Columns: %d (A–%s)%s',
                number_format($highestRow),
                $highestColIndex,
                $highestColumn,
                $scanRows < $highestRow ? sprintf(' · statistics cover the first %s rows', number_format($scanRows)) : '',
            ),
            sprintf('- Rows listed in the table below: %s of %s', number_format($usedRows), number_format($highestRow)),
            '- Columns (statistics over all rows):',
        ];

        foreach ($columns as $col => $stats) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $name = '' !== $stats['label'] ? sprintf('%s "%s"', $letter, $this->escapeCell($stats['label'])) : $letter;

            if (0 === $stats['nonEmpty']) {
                $lines[] = sprintf('  - %s: empty', $name);
                continue;
            }

            $parts = [sprintf('%s values', number_format($stats['nonEmpty']))];

            if ($stats['numeric'] > 0 && null !== $stats['min'] && null !== $stats['max']) {
                $parts[] = $stats['numeric'] === $stats['nonEmpty'] ? 'numeric' : sprintf('%s numeric', number_format($stats['numeric']));
                $parts[] = 'min '.$this->formatNumber($stats['min']);
                $parts[] = 'max '.$this->formatNumber($stats['max']);
                $parts[] = 'sum '.$this->formatNumber($stats['sum']);
                $parts[] = 'mean '.$this->formatNumber($stats['sum'] / $stats['numeric']);
            }

            if ([] !== $stats['distinct']) {
                $distinctCount = count($stats['distinct']);
                $parts[] = $stats['distinctOverflow']
                    ? sprintf('%d+ distinct text values', $distinctCount)
                    : sprintf('%d distinct text value%s', $distinctCount, 1 === $distinctCount ? '' : 's');

                if ($distinctCount > 1 || $stats['numeric'] > 0) {
                    // Count desc, then value asc — deterministic for equal counts.
                    uksort($stats['distinct'], static fn (string|int $a, string|int $b): int => [$stats['distinct'][$b], (string) $a] <=> [$stats['distinct'][$a], (string) $b]);
                    $top = [];
                    foreach (array_slice($stats['distinct'], 0, self::PROFILE_TOP_VALUES, true) as $value => $count) {
                        $top[] = sprintf('%s (%s)', $this->escapeCell(mb_substr((string) $value, 0, 40)), number_format($count));
                    }
                    $parts[] = 'top: '.implode(', ', $top);
                } else {
                    $parts[] = 'value: '.$this->escapeCell(mb_substr((string) array_key_first($stats['distinct']), 0, 60));
                }
            }

            $lines[] = sprintf('  - %s: %s', $name, implode(' · ', $parts));
        }

        return $lines;
    }

    /**
     * @return list<list<mixed>>
     */
    private function rangeValues(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): array
    {
        try {
            /** @var list<list<mixed>> $values */
            $values = $sheet->rangeToArray($range, null, true, false, false);

            return $values;
        } catch (\Throwable) {
            try {
                /** @var list<list<mixed>> $values */
                $values = $sheet->rangeToArray($range, null, false, false, false);

                return $values;
            } catch (\Throwable) {
                return [];
            }
        }
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 1e-9 && abs($value) < 1e15) {
            return number_format($value, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
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

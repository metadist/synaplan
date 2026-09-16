<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class SortRangeTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'sort_range';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Sort a range by one column letter.', [
            'sheet' => ['type' => 'string'],
            'range' => ['type' => 'string'],
            'column' => ['type' => 'string'],
            'descending' => ['type' => 'boolean'],
        ], ['sheet', 'range', 'column']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::XLSX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $sheet = $session->spreadsheet()?->sheet((string) ($input['sheet'] ?? ''));
        if (null === $sheet) {
            return DocumentToolResult::error('Unknown sheet', 'processing.documentStepUnknownSheet', ['sheet' => (string) ($input['sheet'] ?? '')]);
        }
        $addresses = A1Helper::expandRange((string) ($input['range'] ?? ''));
        if ([] === $addresses) {
            return DocumentToolResult::error('Invalid range', 'processing.documentStepInvalidRange');
        }
        $sortCol = strtoupper((string) ($input['column'] ?? ''));
        $desc = (bool) ($input['descending'] ?? false);
        $byRow = [];
        $minCol = PHP_INT_MAX;
        $maxCol = 0;
        $minRow = PHP_INT_MAX;
        $maxRow = 0;
        foreach ($addresses as $address) {
            $parts = A1Helper::split($address);
            if (null === $parts) {
                continue;
            }
            $c = A1Helper::columnIndex($parts[0]);
            $minCol = min($minCol, $c);
            $maxCol = max($maxCol, $c);
            $minRow = min($minRow, $parts[1]);
            $maxRow = max($maxRow, $parts[1]);
            $byRow[$parts[1]][$parts[0]] = $sheet->getCell($address);
        }
        $rows = array_keys($byRow);
        usort($rows, static function (int $a, int $b) use ($byRow, $sortCol, $desc): int {
            $va = ($byRow[$a][$sortCol] ?? null)?->value;
            $vb = ($byRow[$b][$sortCol] ?? null)?->value;
            $cmp = $va <=> $vb;

            return $desc ? -$cmp : $cmp;
        });
        foreach ($addresses as $address) {
            unset($sheet->cells[$address]);
        }
        $destRow = $minRow;
        foreach ($rows as $srcRow) {
            for ($c = $minCol; $c <= $maxCol; ++$c) {
                $letter = A1Helper::columnLetter($c);
                $cell = $byRow[$srcRow][$letter] ?? null;
                if (null !== $cell) {
                    $sheet->setCell($letter.$destRow, $cell);
                }
            }
            ++$destRow;
        }

        return DocumentToolResult::ok('Range sorted', 'processing.documentStepSortRange', ['range' => (string) $input['range']]);
    }
}

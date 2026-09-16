<?php

declare(strict_types=1);

namespace App\Service\Document\Import;

use App\Service\Document\Model\CellModel;
use App\Service\Document\Model\ChartModel;
use App\Service\Document\Model\SheetModel;
use App\Service\Document\Model\SpreadsheetModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SpreadsheetImporter
{
    /**
     * @return array{model: SpreadsheetModel, report: ImportFidelityReport}
     */
    public function import(string $absolutePath): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setIncludeCharts(true);
        $reader->setReadDataOnly(false);
        $book = $reader->load($absolutePath);
        $sheets = [];
        $notes = [];
        foreach ($book->getAllSheets() as $worksheet) {
            $sheets[] = $this->sheetFrom($worksheet, $notes);
        }
        $active = $book->getActiveSheet()->getTitle();
        $book->disconnectWorksheets();

        $lossy = [] !== $notes;

        return [
            'model' => new SpreadsheetModel($sheets, $active),
            'report' => $lossy ? ImportFidelityReport::lossy($notes) : ImportFidelityReport::lossless(),
        ];
    }

    /**
     * @param list<string> $notes
     */
    private function sheetFrom(Worksheet $worksheet, array &$notes): SheetModel
    {
        $cells = [];
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIter = $row->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(true);
            foreach ($cellIter as $cell) {
                $address = $cell->getCoordinate();
                $formula = $cell->isFormula() ? (string) $cell->getValue() : null;
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $type = is_float($value) || is_int($value) ? 'number' : (is_bool($value) ? 'bool' : 'string');
                $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
                $cells[$address] = new CellModel(
                    $value,
                    $type,
                    $formula,
                    'General' === $format ? null : $format,
                );
            }
        }
        $charts = [];
        try {
            foreach ($worksheet->getChartCollection() as $i => $chart) {
                $title = $chart->getTitle()?->getCaption() ?? 'Chart';
                $titleText = is_array($title) ? implode(' ', $title) : (string) $title;
                $charts[] = new ChartModel('imported_'.$i, 'bar', $titleText);
            }
        } catch (\Throwable) {
            $notes[] = 'Some charts on "'.$worksheet->getTitle().'" could not be imported.';
        }

        return new SheetModel($worksheet->getTitle(), $cells, $charts);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Document\Render;

use App\Service\Document\Model\CellModel;
use App\Service\Document\Model\ChartModel;
use App\Service\Document\Model\SpreadsheetModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Deterministic XLSX writer for {@see SpreadsheetModel}.
 */
final class XlsxRenderer
{
    public function render(SpreadsheetModel $model, string $absolutePath): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($model->sheets as $index => $sheetModel) {
            $worksheet = $spreadsheet->createSheet($index);
            $worksheet->setTitle($this->safeTitle($sheetModel->name));

            foreach ($sheetModel->cells as $address => $cell) {
                $this->writeCell($worksheet, $address, $cell);
            }

            foreach ($sheetModel->charts as $chart) {
                $this->addChart($worksheet, $sheetModel->name, $chart);
            }
        }

        $active = $model->sheet($model->activeSheet);
        if (null !== $active) {
            $spreadsheet->setActiveSheetIndex($this->indexOf($model, $active->name));
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $writer = new XlsxWriter($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($absolutePath);
        $spreadsheet->disconnectWorksheets();
    }

    private function writeCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet, string $address, CellModel $cell): void
    {
        if (null !== $cell->formula && '' !== $cell->formula) {
            $formula = str_starts_with($cell->formula, '=') ? $cell->formula : '='.$cell->formula;
            $worksheet->setCellValue($address, $formula);
        } elseif ('number' === $cell->type) {
            $worksheet->setCellValueExplicit($address, (float) $cell->value, DataType::TYPE_NUMERIC);
        } elseif ('bool' === $cell->type) {
            $worksheet->setCellValueExplicit($address, (bool) $cell->value, DataType::TYPE_BOOL);
        } elseif (null === $cell->value) {
            $worksheet->setCellValueExplicit($address, '', DataType::TYPE_STRING);
        } else {
            $worksheet->setCellValueExplicit($address, (string) $cell->value, DataType::TYPE_STRING);
        }

        if (null !== $cell->numberFormat && '' !== $cell->numberFormat) {
            $worksheet->getStyle($address)->getNumberFormat()->setFormatCode($cell->numberFormat);
        }

        if (null !== $cell->style) {
            $style = $worksheet->getStyle($address);
            $style->getFont()->setBold($cell->style->bold);
            $style->getFont()->setItalic($cell->style->italic);
            if (null !== $cell->style->color) {
                $style->getFont()->getColor()->setRGB(ltrim($cell->style->color, '#'));
            }
            if (null !== $cell->style->fill) {
                $style->getFill()->setFillType(Fill::FILL_SOLID);
                $style->getFill()->getStartColor()->setRGB(ltrim($cell->style->fill, '#'));
            }
            if ('right' === $cell->style->align) {
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } elseif ('center' === $cell->style->align) {
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
    }

    private function addChart(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet, string $sheetName, ChartModel $chart): void
    {
        if ('' === $chart->categoriesRange || '' === $chart->valuesRange) {
            return;
        }

        $quoted = "'".str_replace("'", "''", $sheetName)."'";
        $categories = $quoted.'!'.$chart->categoriesRange;
        $values = $quoted.'!'.$chart->valuesRange;
        $catCount = $this->rangeLength($chart->categoriesRange);

        $seriesType = match ($chart->type) {
            'line' => DataSeries::TYPE_LINECHART,
            'pie' => DataSeries::TYPE_PIECHART,
            default => DataSeries::TYPE_BARCHART,
        };

        $xAxis = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categories, null, $catCount);
        $dataValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $values, null, $catCount);
        $series = new DataSeries(
            $seriesType,
            DataSeries::GROUPING_CLUSTERED,
            range(0, 0),
            [],
            [$xAxis],
            [$dataValues],
        );
        $plotArea = new PlotArea(null, [$series]);
        $excelChart = new Chart(
            $chart->id,
            '' !== $chart->title ? new Title($chart->title) : null,
            new Legend(Legend::POSITION_RIGHT, null, false),
            $plotArea,
        );
        $anchor = '' !== $chart->anchor ? $chart->anchor : 'D2';
        $excelChart->setTopLeftPosition($anchor);
        $bottom = $this->offsetAddress($anchor, 8, 14);
        $excelChart->setBottomRightPosition($bottom);
        $worksheet->addChart($excelChart);
    }

    private function rangeLength(string $range): int
    {
        if (!str_contains($range, ':')) {
            return 1;
        }
        [$start, $end] = explode(':', $range, 2);
        [, $startRow] = Coordinate::coordinateFromString($start);
        [, $endRow] = Coordinate::coordinateFromString($end);

        return max(1, abs((int) $endRow - (int) $startRow) + 1);
    }

    private function offsetAddress(string $address, int $cols, int $rows): string
    {
        [$col, $row] = Coordinate::coordinateFromString($address);
        $colIndex = Coordinate::columnIndexFromString($col);

        return Coordinate::stringFromColumnIndex($colIndex + $cols).((int) $row + $rows);
    }

    private function indexOf(SpreadsheetModel $model, string $name): int
    {
        foreach ($model->sheets as $i => $sheet) {
            if (0 === strcasecmp($sheet->name, $name)) {
                return $i;
            }
        }

        return 0;
    }

    private function safeTitle(string $name): string
    {
        $clean = preg_replace('/[\\\\\/\\*\\?\\:\\[\\]]/', '', $name) ?? 'Sheet';
        $clean = trim($clean);
        if ('' === $clean) {
            $clean = 'Sheet';
        }

        return substr($clean, 0, 31);
    }
}

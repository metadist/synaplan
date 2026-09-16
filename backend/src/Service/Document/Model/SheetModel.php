<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

final class SheetModel
{
    /**
     * @param array<string, CellModel>   $cells              A1-keyed
     * @param list<ChartModel>           $charts
     * @param list<array<string, mixed>> $conditionalFormats
     */
    public function __construct(
        public string $name,
        public array $cells = [],
        public array $charts = [],
        public array $conditionalFormats = [],
    ) {
    }

    public function setCell(string $address, CellModel $cell): void
    {
        $this->cells[strtoupper($address)] = $cell;
    }

    public function getCell(string $address): ?CellModel
    {
        return $this->cells[strtoupper($address)] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $cells = [];
        foreach ($this->cells as $address => $cell) {
            $cells[$address] = $cell->toArray();
        }
        $charts = [];
        foreach ($this->charts as $chart) {
            $charts[] = $chart->toArray();
        }

        return [
            'name' => $this->name,
            'cells' => $cells,
            'charts' => $charts,
            'conditionalFormats' => $this->conditionalFormats,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cells = [];
        if (isset($data['cells']) && is_array($data['cells'])) {
            foreach ($data['cells'] as $address => $cellData) {
                if (is_array($cellData)) {
                    $cells[strtoupper((string) $address)] = CellModel::fromArray($cellData);
                }
            }
        }
        $charts = [];
        if (isset($data['charts']) && is_array($data['charts'])) {
            foreach ($data['charts'] as $chartData) {
                if (is_array($chartData)) {
                    $charts[] = ChartModel::fromArray($chartData);
                }
            }
        }

        $formats = [];
        if (isset($data['conditionalFormats']) && is_array($data['conditionalFormats'])) {
            foreach ($data['conditionalFormats'] as $format) {
                if (is_array($format)) {
                    $formats[] = $format;
                }
            }
        }

        return new self(
            is_string($data['name'] ?? null) ? $data['name'] : 'Sheet1',
            $cells,
            $charts,
            $formats,
        );
    }
}

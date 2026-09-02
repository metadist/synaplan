<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

use App\Service\Document\DocumentKind;

/**
 * Versioned spreadsheet model. Tools patch this, never the XLSX ZIP.
 */
final class SpreadsheetModel
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<SheetModel> $sheets
     */
    public function __construct(
        public array $sheets = [],
        public string $activeSheet = 'Sheet1',
    ) {
        if ([] === $this->sheets) {
            $this->sheets = [new SheetModel($this->activeSheet)];
        }
    }

    public static function empty(string $sheetName = 'Sheet1'): self
    {
        return new self([new SheetModel($sheetName)], $sheetName);
    }

    public function kind(): string
    {
        return DocumentKind::XLSX;
    }

    public function sheet(string $name): ?SheetModel
    {
        foreach ($this->sheets as $sheet) {
            if (0 === strcasecmp($sheet->name, $name)) {
                return $sheet;
            }
        }

        return null;
    }

    public function requireSheet(string $name): SheetModel
    {
        $sheet = $this->sheet($name);
        if (null === $sheet) {
            throw new \InvalidArgumentException(sprintf('Unknown sheet "%s"', $name));
        }

        return $sheet;
    }

    public function addSheet(string $name): SheetModel
    {
        if (null !== $this->sheet($name)) {
            throw new \InvalidArgumentException(sprintf('Sheet "%s" already exists', $name));
        }
        $sheet = new SheetModel($name);
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'kind' => DocumentKind::XLSX,
            'activeSheet' => $this->activeSheet,
            'sheets' => array_map(static fn (SheetModel $s): array => $s->toArray(), $this->sheets),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sheets = [];
        if (isset($data['sheets']) && is_array($data['sheets'])) {
            foreach ($data['sheets'] as $sheetData) {
                if (is_array($sheetData)) {
                    $sheets[] = SheetModel::fromArray($sheetData);
                }
            }
        }
        $active = is_string($data['activeSheet'] ?? null) ? $data['activeSheet'] : 'Sheet1';

        return new self($sheets, $active);
    }
}

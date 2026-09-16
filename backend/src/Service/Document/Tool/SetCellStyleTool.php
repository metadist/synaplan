<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\CellModel;
use App\Service\Document\Model\StyleModel;

final class SetCellStyleTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_cell_style';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Set bold/italic/fill/color/align on a range.', [
            'sheet' => ['type' => 'string'],
            'range' => ['type' => 'string'],
            'bold' => ['type' => 'boolean'],
            'italic' => ['type' => 'boolean'],
            'fill' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'align' => ['type' => 'string'],
        ], ['sheet', 'range']);
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
        $style = new StyleModel(
            (bool) ($input['bold'] ?? false),
            (bool) ($input['italic'] ?? false),
            isset($input['fill']) && is_string($input['fill']) ? $input['fill'] : null,
            isset($input['color']) && is_string($input['color']) ? $input['color'] : null,
            isset($input['align']) && is_string($input['align']) ? $input['align'] : null,
        );
        foreach ($addresses as $address) {
            $existing = $sheet->getCell($address) ?? new CellModel();
            $sheet->setCell($address, new CellModel($existing->value, $existing->type, $existing->formula, $existing->numberFormat, $style));
        }

        return DocumentToolResult::ok('Style applied', 'processing.documentStepSetCellStyle', ['range' => (string) $input['range']]);
    }
}

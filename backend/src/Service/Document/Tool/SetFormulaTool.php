<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\CellModel;

final class SetFormulaTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_formula';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Set a formula on one cell.', [
            'sheet' => ['type' => 'string'],
            'address' => ['type' => 'string'],
            'formula' => ['type' => 'string'],
        ], ['sheet', 'address', 'formula']);
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
        $address = strtoupper((string) ($input['address'] ?? ''));
        if (null === A1Helper::split($address)) {
            return DocumentToolResult::error('Invalid address', 'processing.documentStepInvalidRange');
        }
        $existing = $sheet->getCell($address);
        $sheet->setCell($address, new CellModel(
            $existing?->value,
            'number',
            (string) ($input['formula'] ?? ''),
            $existing?->numberFormat,
            $existing?->style,
        ));

        return DocumentToolResult::ok('Formula set on '.$address, 'processing.documentStepSetFormula', ['address' => $address]);
    }
}

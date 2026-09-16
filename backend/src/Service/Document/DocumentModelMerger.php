<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SheetModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;

/**
 * True merge on the model (B7): append sheets / Word blocks / slides.
 */
final class DocumentModelMerger
{
    public function merge(SpreadsheetModel|WordModel|DeckModel $base, SpreadsheetModel|WordModel|DeckModel $incoming): int
    {
        if ($base instanceof SpreadsheetModel && $incoming instanceof SpreadsheetModel) {
            foreach ($incoming->sheets as $sheet) {
                $name = $sheet->name;
                $suffix = 2;
                while (null !== $base->sheet($name)) {
                    $name = $sheet->name.' '.$suffix;
                    ++$suffix;
                }
                $base->sheets[] = new SheetModel($name, $sheet->cells, $sheet->charts, $sheet->conditionalFormats);
            }

            return 1;
        }
        if ($base instanceof WordModel && $incoming instanceof WordModel) {
            $offset = [] === $base->blocks ? 0 : 1;
            foreach ($incoming->blocks as $block) {
                $payload = $block->payload;
                if (WordBlock::TYPE_HEADING === $block->type && $offset > 0) {
                    $level = (int) ($payload['level'] ?? 1);
                    $payload['level'] = min(6, $level + $offset);
                }
                $base->blocks[] = new WordBlock(uniqid('blk_', true), $block->type, $payload);
            }

            return 1;
        }
        if ($base instanceof DeckModel && $incoming instanceof DeckModel) {
            foreach ($incoming->slides as $slide) {
                $base->slides[] = $slide;
            }

            return 1;
        }

        return 0;
    }
}

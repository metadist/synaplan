<?php

declare(strict_types=1);

namespace App\Service\Document\Persist;

use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;

/**
 * Text projection for BFILETEXT (search, digests, MCP). Never stores JSON there.
 */
final class DocumentTextProjector
{
    public function project(SpreadsheetModel|WordModel|DeckModel $model): string
    {
        if ($model instanceof SpreadsheetModel) {
            return $this->spreadsheet($model);
        }
        if ($model instanceof WordModel) {
            return $this->word($model);
        }

        return $this->deck($model);
    }

    private function spreadsheet(SpreadsheetModel $model): string
    {
        $parts = [];
        foreach ($model->sheets as $sheet) {
            $parts[] = '## '.$sheet->name;
            foreach ($sheet->cells as $address => $cell) {
                $parts[] = $address.': '.($cell->formula ?? (string) $cell->value);
            }
        }

        return implode("\n", $parts);
    }

    private function word(WordModel $model): string
    {
        $parts = [];
        foreach ($model->blocks as $block) {
            if (WordBlock::TYPE_HEADING === $block->type) {
                $level = max(1, (int) ($block->payload['level'] ?? 1));
                $parts[] = str_repeat('#', $level).' '.((string) ($block->payload['text'] ?? ''));
            } elseif (WordBlock::TYPE_PARAGRAPH === $block->type) {
                $parts[] = (string) ($block->payload['text'] ?? '');
            }
        }

        return implode("\n\n", $parts);
    }

    private function deck(DeckModel $model): string
    {
        $parts = [];
        foreach ($model->slides as $i => $slide) {
            $title = (string) ($slide['title'] ?? ('Slide '.($i + 1)));
            $parts[] = '## '.$title;
            $bullets = $slide['bullets'] ?? [];
            if (is_array($bullets)) {
                foreach ($bullets as $bullet) {
                    $parts[] = '- '.(is_string($bullet) ? $bullet : '');
                }
            }
        }

        return implode("\n", $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Document\Render;

use App\Service\Document\Model\DeckModel;
use App\Service\File\Presentation\PptxRenderer;

/**
 * Adapter: structured {@see DeckModel} → existing {@see PptxRenderer}.
 */
final readonly class PptxRendererAdapter
{
    public function __construct(
        private PptxRenderer $pptxRenderer,
    ) {
    }

    public function render(DeckModel $model, string $absolutePath): void
    {
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->pptxRenderer->render($model->toSlideDeck(), $absolutePath, []);
    }
}

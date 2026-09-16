<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;

/**
 * One content-extraction adapter (Tika, Docling, vision, STT, …).
 */
interface ContentExtractorInterface
{
    public function key(): string;

    public function descriptor(): PlugDescriptor;

    public function supports(ExtractionRequest $request): bool;

    public function extract(ExtractionRequest $request): ExtractionResult;

    public function health(): PlugHealth;
}

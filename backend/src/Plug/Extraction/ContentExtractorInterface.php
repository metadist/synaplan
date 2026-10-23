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

    /**
     * True when this adapter calls an AI provider.
     *
     * Self-hosted adapters (Tika, Docling, native text) stay false. The file
     * upload path does not read this flag. {@see ExtractionRegistry::chainFor()}
     * drops a true adapter when a local world must not leave the machine.
     */
    public function runsInCloud(): bool;

    public function health(): PlugHealth;
}

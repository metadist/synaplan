<?php

declare(strict_types=1);

namespace App\AI\Catalog\Scaffold;

/**
 * The files the scaffold writes and the snippets it prints for the developer
 * to paste (model rows and the five locale help keys), before anything
 * touches the disk.
 */
final readonly class ProviderScaffoldPlan
{
    /**
     * @param array<string, string> $files    absolute path => contents
     * @param array<string, string> $snippets title => text to paste
     */
    public function __construct(
        public array $files,
        public array $snippets,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * Color themes a generated presentation can use.
 *
 * The model picks one by name through the `{{PPTX:theme=...}}` directive, so the
 * case values are the user-facing vocabulary: keep them short, English and
 * stable — renaming one silently invalidates the decks a user asks to edit
 * later, because the stored directive would no longer resolve.
 */
enum PptxTheme: string
{
    case Default = 'default';
    case Ocean = 'ocean';
    case Midnight = 'midnight';
    case Sunset = 'sunset';
    case Forest = 'forest';
    case Mono = 'mono';

    /**
     * Resolve a theme name from the directive. An unknown or missing name falls
     * back to the default theme instead of failing the document.
     */
    public static function fromName(?string $name): self
    {
        if (null === $name) {
            return self::Default;
        }

        return self::tryFrom(strtolower(trim($name))) ?? self::Default;
    }

    /**
     * Names offered to the model, for the prompt block.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $theme): string => $theme->value, self::cases());
    }

    public function palette(): PptxPalette
    {
        return match ($this) {
            self::Default => new PptxPalette(
                background: 'FFFFFF',
                title: '1E3A5F',
                body: '2D3748',
                accent: '2563EB',
                muted: '5A6879',
                onAccent: 'FFFFFF',
            ),
            self::Ocean => new PptxPalette(
                background: 'F3F9FC',
                title: '0B3B4F',
                body: '1F3A44',
                accent: '0E7490',
                muted: '46626C',
                onAccent: 'FFFFFF',
            ),
            self::Midnight => new PptxPalette(
                background: '111827',
                title: 'F9FAFB',
                body: 'E5E7EB',
                accent: '60A5FA',
                muted: '9CA3AF',
                onAccent: '111827',
            ),
            self::Sunset => new PptxPalette(
                background: 'FFF8F3',
                title: '7C2D12',
                body: '3F2A20',
                accent: 'C2410C',
                muted: '7A5140',
                onAccent: 'FFFFFF',
            ),
            self::Forest => new PptxPalette(
                background: 'F4FAF6',
                title: '14532D',
                body: '22322A',
                accent: '15803D',
                muted: '46614F',
                onAccent: 'FFFFFF',
            ),
            self::Mono => new PptxPalette(
                background: 'FFFFFF',
                title: '111827',
                body: '1F2937',
                accent: '4B5563',
                muted: '626C7A',
                onAccent: 'FFFFFF',
            ),
        };
    }
}

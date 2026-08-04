<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Presentation;

use App\Service\File\Presentation\PptxTheme;
use PHPUnit\Framework\TestCase;

class PptxThemeTest extends TestCase
{
    /** WCAG AA for normal-size text. */
    private const MIN_CONTRAST = 4.5;

    public function testUnknownOrMissingNameFallsBackToTheDefaultTheme(): void
    {
        $this->assertSame(PptxTheme::Default, PptxTheme::fromName(null));
        $this->assertSame(PptxTheme::Default, PptxTheme::fromName('neon'));
        $this->assertSame(PptxTheme::Default, PptxTheme::fromName(''));
    }

    public function testNamesAreMatchedCaseInsensitivelyAndTrimmed(): void
    {
        $this->assertSame(PptxTheme::Ocean, PptxTheme::fromName(' Ocean '));
        $this->assertSame(PptxTheme::Midnight, PptxTheme::fromName('MIDNIGHT'));
    }

    public function testNamesListsEveryTheme(): void
    {
        $this->assertSame(
            ['default', 'ocean', 'midnight', 'sunset', 'forest', 'mono'],
            PptxTheme::names(),
        );
    }

    /**
     * A projected slide is the least forgiving surface there is, so every ink
     * color of every theme has to clear WCAG AA against the surface it sits on.
     * A palette that regresses fails here instead of shipping.
     */
    public function testEveryThemeMeetsWcagAaOnItsOwnBackground(): void
    {
        foreach (PptxTheme::cases() as $theme) {
            $palette = $theme->palette();

            $inks = [
                'title' => $palette->title,
                'body' => $palette->body,
                'accent' => $palette->accent,
                'muted' => $palette->muted,
            ];
            foreach ($inks as $role => $color) {
                $this->assertGreaterThanOrEqual(
                    self::MIN_CONTRAST,
                    $this->contrast($color, $palette->background),
                    sprintf('Theme "%s": %s on background is below WCAG AA', $theme->value, $role),
                );
            }

            $this->assertGreaterThanOrEqual(
                self::MIN_CONTRAST,
                $this->contrast($palette->onAccent, $palette->accent),
                sprintf('Theme "%s": table header text on the accent fill is below WCAG AA', $theme->value),
            );
        }
    }

    public function testEveryColorIsASixDigitHexValue(): void
    {
        foreach (PptxTheme::cases() as $theme) {
            $palette = $theme->palette();

            $colors = [
                'background' => $palette->background,
                'title' => $palette->title,
                'body' => $palette->body,
                'accent' => $palette->accent,
                'muted' => $palette->muted,
                'onAccent' => $palette->onAccent,
            ];
            foreach ($colors as $role => $color) {
                $this->assertMatchesRegularExpression(
                    '/^[0-9A-F]{6}$/',
                    $color,
                    sprintf('Theme "%s": %s must be uppercase RGB hex without a hash', $theme->value, $role),
                );
            }
        }
    }

    private function contrast(string $first, string $second): float
    {
        $a = $this->relativeLuminance($first);
        $b = $this->relativeLuminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}

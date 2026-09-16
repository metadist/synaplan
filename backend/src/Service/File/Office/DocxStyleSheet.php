<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Default Word look for generated DOCX files (#1396).
 *
 * Colors match {@see \App\Service\File\Presentation\PptxTheme::Default} so
 * Word and PowerPoint feel like one product.
 */
final class DocxStyleSheet
{
    public const TABLE_STYLE = 'SynaplanTable';
    public const BODY_FONT = 'Calibri';
    public const BODY_SIZE = 11;
    public const TITLE_COLOR = '1E3A5F';
    public const BODY_COLOR = '2D3748';
    public const MUTED_COLOR = '5A6879';
    public const TABLE_BORDER = 'CBD5E1';
    public const TABLE_HEADER_FILL = 'E8EEF7';

    private function __construct()
    {
    }

    public static function apply(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName(self::BODY_FONT);
        $phpWord->setDefaultFontSize(self::BODY_SIZE);
        $phpWord->setDefaultParagraphStyle([
            'spaceAfter' => 160,
            'spaceBefore' => 0,
            'lineHeight' => 1.15,
        ]);

        $headingSizes = [1 => 22, 2 => 16, 3 => 13, 4 => 12, 5 => 11, 6 => 11];
        foreach ($headingSizes as $depth => $size) {
            $phpWord->addTitleStyle(
                $depth,
                [
                    'bold' => true,
                    'size' => $size,
                    'color' => self::TITLE_COLOR,
                    'name' => self::BODY_FONT,
                ],
                [
                    'spaceBefore' => 1 === $depth ? 360 : 240,
                    'spaceAfter' => 120,
                    'keepNext' => true,
                ],
            );
        }

        $phpWord->addTableStyle(
            self::TABLE_STYLE,
            [
                'borderSize' => 6,
                'borderColor' => self::TABLE_BORDER,
                'cellMargin' => 80,
            ],
            [
                'bgColor' => self::TABLE_HEADER_FILL,
                'bold' => true,
                'color' => self::TITLE_COLOR,
            ],
        );

        $phpWord->addNumberingStyle('SynaplanList', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => 'bullet', 'text' => '•', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
                ['format' => 'bullet', 'text' => '◦', 'left' => 1440, 'hanging' => 360, 'tabPos' => 1440],
            ],
        ]);
    }

    /**
     * @return array{marginTop: int, marginBottom: int, marginLeft: int, marginRight: int}
     */
    public static function sectionSettings(): array
    {
        return [
            'marginTop' => 1440,
            'marginBottom' => 1440,
            'marginLeft' => 1440,
            'marginRight' => 1440,
        ];
    }

    public static function decorateSection(Section $section): void
    {
        $footer = $section->addFooter();
        $footer->addPreserveText(
            '{PAGE}',
            ['size' => 9, 'color' => self::MUTED_COLOR, 'name' => self::BODY_FONT],
            ['alignment' => Jc::CENTER],
        );
    }
}

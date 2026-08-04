<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\AbstractGraphic;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Font;
use Psr\Log\LoggerInterface;

/**
 * Draws a parsed {@see SlideDeck} as a real PowerPoint file.
 *
 * All geometry is expressed in the 960x540 pixel coordinate system of a 16:9
 * slide, which is what PhpPresentation expects for shape offsets and sizes.
 * The layout is deliberately a fixed, tasteful one rather than a set of knobs:
 * the model chooses a color theme, everything else follows from the content, so
 * a generated deck is consistent and cannot come out broken.
 */
final readonly class PptxRenderer
{
    private const SLIDE_WIDTH = 960;
    private const SLIDE_HEIGHT = 540;
    private const MARGIN_X = 56;

    private const TITLE_TOP = 44;
    private const TITLE_HEIGHT = 60;
    private const RULE_Y = 112;
    private const RULE_WIDTH = 104;
    private const RULE_THICKNESS = 3;

    private const BODY_TOP = 134;
    private const BODY_BOTTOM = self::SLIDE_HEIGHT - 54;
    private const FOOTER_TOP = self::SLIDE_HEIGHT - 42;
    private const FOOTER_WIDTH = 60;
    private const FOOTER_HEIGHT = 22;

    private const COVER_TITLE_TOP = 196;
    private const COVER_TITLE_HEIGHT = 96;
    private const COVER_SUBTITLE_HEIGHT = 64;

    /** A cover with a picture moves its headline up to make room for it. */
    private const COVER_TITLE_TOP_WITH_IMAGE = 58;
    private const COVER_TITLE_HEIGHT_WITH_IMAGE = 74;
    private const COVER_SUBTITLE_HEIGHT_WITH_IMAGE = 40;

    private const IMAGE_COLUMN_WIDTH = 300;
    private const COLUMN_GAP = 28;
    private const IMAGE_GAP = 14;
    private const BLOCK_GAP = 12;
    private const RULE_GAP = 10;
    private const AFTER_RULE_GAP = 18;

    /** Below this a text box or a picture is not worth placing. */
    private const MIN_ELEMENT_HEIGHT = 40;

    /** More pictures than this stop being visible on a single slide. */
    private const MAX_IMAGES_PER_SLIDE = 4;

    private const FONT_FAMILY = 'Calibri';
    private const FONT_FAMILY_MONO = 'Consolas';

    private const FONT_COVER_TITLE = 40;
    private const FONT_COVER_TITLE_WITH_IMAGE = 32;
    private const FONT_COVER_SUBTITLE = 20;
    private const FONT_TITLE = 28;
    private const FONT_SUBHEADING = 20;
    private const FONT_FOOTER = 11;
    private const FONT_TABLE = 13;

    /** Body text size per indent level. */
    private const FONT_BODY = [18, 16, 15, 14];

    /** Indent of a body line per level, in pixels. */
    private const BODY_INDENT = [0, 30, 60, 90];

    /**
     * Hanging indent so a wrapped line aligns with its own text. Numbers need
     * more room than a glyph, otherwise "10." runs into the first word.
     */
    private const BULLET_HANGING_INDENT = -16;
    private const NUMBER_HANGING_INDENT = -28;

    private const BULLET_CHARS = ['•', '–', '▪', '·'];

    /** A slide with this many body lines gets smaller type so it still fits. */
    private const DENSE_BODY_LINES = 10;
    private const VERY_DENSE_BODY_LINES = 15;

    private const TABLE_ROW_MIN_HEIGHT = 26;
    private const TABLE_ROW_MAX_HEIGHT = 40;

    /**
     * Far above the sequential indexes the writer's own drawing hash table hands
     * out, so a pre-assigned index can never collide with one of them.
     */
    private const DRAWING_HASH_INDEX_BASE = 1_000_000;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $images image marker reference => absolute path
     *
     * @throws \Throwable when the presentation cannot be written
     */
    public function render(SlideDeck $deck, string $absolutePath, array $images = []): void
    {
        $presentation = new PhpPresentation();
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $palette = $deck->theme->palette();
        $total = count($deck->slides);

        $documentTitle = $deck->slides[0]->title ?? null;
        if (null !== $documentTitle) {
            $presentation->getDocumentProperties()->setTitle($documentTitle);
        }

        foreach ($deck->slides as $index => $slide) {
            $target = 0 === $index ? $presentation->getActiveSlide() : $presentation->createSlide();
            $this->renderSlide($target, $slide, $palette, $images, $index + 1, $total);

            // One instance per slide: sharing a transition object across slides
            // would make them compare equal and collapse in the writer's cache.
            $transition = $deck->transition->toTransition();
            if (null !== $transition) {
                $target->setTransition($transition);
            }
        }

        $this->assignDrawingHashIndexes($presentation);

        PresentationIOFactory::createWriter($presentation, 'PowerPoint2007')->save($absolutePath);
    }

    /**
     * The writer registers every drawing in a hash table keyed by its hash index,
     * which stays null until something assigns one — and looking up a null array
     * key is deprecated as of PHP 8.3, so each embedded picture would emit a
     * notice. Pre-assigning indexes leaves the lookup result unchanged (none of
     * them is a key of that table) and keeps the log clean.
     */
    private function assignDrawingHashIndexes(PhpPresentation $presentation): void
    {
        $index = self::DRAWING_HASH_INDEX_BASE;

        foreach ($presentation->getAllSlides() as $slide) {
            foreach ($slide->getShapeCollection() as $shape) {
                if ($shape instanceof AbstractGraphic) {
                    $shape->setHashIndex($index);
                    ++$index;
                }
            }
        }
    }

    /**
     * @param array<string, string> $images
     */
    private function renderSlide(Slide $target, SlideContent $slide, PptxPalette $palette, array $images, int $number, int $total): void
    {
        $target->setBackground((new BackgroundColor())->setColor(new Color('FF'.$palette->background)));

        $imagePaths = $this->resolveImages($slide->imageReferences, $images);

        if ($slide->titleSlide) {
            $this->renderCoverSlide($target, $slide, $palette, $imagePaths);

            return;
        }

        $this->renderContentSlide($target, $slide, $palette, $imagePaths, $number, $total);
    }

    /**
     * Opening slide: headline, optional standfirst, optional picture. No slide
     * number — a cover is not counted in a printed deck either.
     *
     * @param list<string> $imagePaths
     */
    private function renderCoverSlide(Slide $target, SlideContent $slide, PptxPalette $palette, array $imagePaths): void
    {
        $subtitle = $this->joinBullets($slide->bullets);
        $hasImage = [] !== $imagePaths;
        $width = self::SLIDE_WIDTH - 2 * self::MARGIN_X;

        $titleTop = $hasImage ? self::COVER_TITLE_TOP_WITH_IMAGE : self::COVER_TITLE_TOP;
        $titleSize = $hasImage ? self::FONT_COVER_TITLE_WITH_IMAGE : self::FONT_COVER_TITLE;
        $titleHeight = $hasImage ? self::COVER_TITLE_HEIGHT_WITH_IMAGE : self::COVER_TITLE_HEIGHT;

        $this->addTextShape(
            $target,
            $slide->title ?? ' ',
            self::MARGIN_X,
            $titleTop,
            $width,
            $titleHeight,
            $titleSize,
            $palette->title,
            bold: true,
            horizontal: Alignment::HORIZONTAL_CENTER,
        );

        $ruleY = $titleTop + $titleHeight + self::RULE_GAP;
        $this->addAccentRule($target, intdiv(self::SLIDE_WIDTH - self::RULE_WIDTH, 2), $ruleY, $palette);

        $contentTop = $ruleY + self::AFTER_RULE_GAP;
        if ('' !== $subtitle) {
            $subtitleHeight = $hasImage ? self::COVER_SUBTITLE_HEIGHT_WITH_IMAGE : self::COVER_SUBTITLE_HEIGHT;
            $this->addTextShape(
                $target,
                $subtitle,
                self::MARGIN_X,
                $contentTop,
                $width,
                $subtitleHeight,
                self::FONT_COVER_SUBTITLE,
                $palette->muted,
                horizontal: Alignment::HORIZONTAL_CENTER,
            );
            $contentTop += $subtitleHeight + self::BLOCK_GAP;
        }

        if ($hasImage) {
            $this->placeImages($target, $imagePaths, self::MARGIN_X, $contentTop, $width, self::BODY_BOTTOM - $contentTop);
        }
    }

    /**
     * @param list<string> $imagePaths
     */
    private function renderContentSlide(Slide $target, SlideContent $slide, PptxPalette $palette, array $imagePaths, int $number, int $total): void
    {
        $bodyTop = self::BODY_TOP;

        if (null !== $slide->title) {
            $this->addTextShape(
                $target,
                $slide->title,
                self::MARGIN_X,
                self::TITLE_TOP,
                self::SLIDE_WIDTH - 2 * self::MARGIN_X,
                self::TITLE_HEIGHT,
                self::FONT_TITLE,
                $palette->title,
                bold: true,
            );
            $this->addAccentRule($target, self::MARGIN_X, self::RULE_Y, $palette);
        } else {
            $bodyTop = self::TITLE_TOP;
        }

        $hasBody = $slide->hasBody();
        $hasImages = [] !== $imagePaths;
        $bodyHeight = self::BODY_BOTTOM - $bodyTop;
        $bodyWidth = self::SLIDE_WIDTH - 2 * self::MARGIN_X;

        if ($hasImages && $hasBody) {
            $bodyWidth = self::SLIDE_WIDTH - 2 * self::MARGIN_X - self::IMAGE_COLUMN_WIDTH - self::COLUMN_GAP;
            $this->placeImages(
                $target,
                $imagePaths,
                self::SLIDE_WIDTH - self::MARGIN_X - self::IMAGE_COLUMN_WIDTH,
                $bodyTop,
                self::IMAGE_COLUMN_WIDTH,
                $bodyHeight,
            );
        } elseif ($hasImages) {
            $this->placeImages($target, $imagePaths, self::MARGIN_X, $bodyTop, $bodyWidth, $bodyHeight);
        }

        if ($hasBody) {
            $this->addBody($target, $slide, $palette, $bodyTop, $bodyWidth, $bodyHeight);
        }

        if (!$hasBody && !$hasImages && null === $slide->title) {
            // Never emit a slide without a single shape.
            $this->addTextShape(
                $target,
                ' ',
                self::MARGIN_X,
                $bodyTop,
                $bodyWidth,
                self::MIN_ELEMENT_HEIGHT,
                self::FONT_BODY[0],
                $palette->body,
            );
        }

        if ($total > 1) {
            $this->addTextShape(
                $target,
                (string) $number,
                self::SLIDE_WIDTH - self::MARGIN_X - self::FOOTER_WIDTH,
                self::FOOTER_TOP,
                self::FOOTER_WIDTH,
                self::FOOTER_HEIGHT,
                self::FONT_FOOTER,
                $palette->muted,
                horizontal: Alignment::HORIZONTAL_RIGHT,
            );
        }
    }

    /**
     * Body area: the text lines first, then the tables underneath them.
     */
    private function addBody(Slide $target, SlideContent $slide, PptxPalette $palette, int $top, int $width, int $height): void
    {
        $textHeight = $height;

        if (count($slide->tables) > 1) {
            $this->logger->warning('PptxRenderer: only the first table of a slide is rendered', [
                'tables' => count($slide->tables),
            ]);
        }

        if ([] !== $slide->tables) {
            $tableHeight = min(
                intdiv($height, [] === $slide->bullets ? 1 : 2),
                $this->tableHeight($slide->tables[0]),
            );
            $textHeight = $height - $tableHeight - self::IMAGE_GAP;

            if ([] === $slide->bullets) {
                $this->addTable($target, $slide->tables[0], $palette, self::MARGIN_X, $top, $width, $tableHeight);

                return;
            }

            $this->addTable(
                $target,
                $slide->tables[0],
                $palette,
                self::MARGIN_X,
                $top + $textHeight + self::IMAGE_GAP,
                $width,
                $tableHeight,
            );
        }

        if ([] === $slide->bullets) {
            return;
        }

        $shape = $target->createRichTextShape();
        $shape->setOffsetX(self::MARGIN_X)
            ->setOffsetY($top)
            ->setWidth($width)
            ->setHeight(max(self::MIN_ELEMENT_HEIGHT, $textHeight));
        $shape->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->setWrap(RichText::WRAP_SQUARE);
        // PowerPoint re-flows shrink-on-overflow text when the deck is opened, so
        // an unusually wordy slide stays readable instead of spilling off-canvas.
        $shape->setAutoFit(RichText::AUTOFIT_NORMAL);

        $scale = $this->densityScale(count($slide->bullets));

        foreach ($slide->bullets as $index => $bullet) {
            $paragraph = 0 === $index ? $shape->getActiveParagraph() : $shape->createParagraph();
            $this->addBodyParagraph($paragraph, $bullet, $palette, $scale);
        }
    }

    private function addBodyParagraph(Paragraph $paragraph, SlideBullet $bullet, PptxPalette $palette, float $scale): void
    {
        $level = max(0, min($bullet->level, SlideBullet::MAX_LEVEL));
        $size = $bullet->heading
            ? self::FONT_SUBHEADING
            : (int) round(self::FONT_BODY[$level] * $scale);
        $color = $bullet->heading ? $palette->accent : $palette->body;
        $hangingIndent = match ($bullet->marker) {
            SlideBulletMarker::None => 0,
            SlideBulletMarker::Dot => self::BULLET_HANGING_INDENT,
            SlideBulletMarker::Number => self::NUMBER_HANGING_INDENT,
        };

        $paragraph->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setMarginLeft(self::BODY_INDENT[$level] - $hangingIndent)
            ->setIndent($hangingIndent);

        $paragraph->setSpacingBefore($bullet->heading ? 10 : 0);
        $paragraph->setSpacingAfter(0 !== $hangingIndent ? 6 : 9);

        // The bullet glyph inherits the paragraph font, not the runs.
        $paragraph->setFont((new Font())->setName(self::FONT_FAMILY)->setSize($size)->setColor(new Color('FF'.$color)));
        $paragraph->setBulletStyle($this->bulletStyle($bullet, $level, $palette));

        foreach ($bullet->runs as $run) {
            $textRun = $paragraph->createTextRun($run->text);
            $textRun->getFont()
                ->setName($run->monospace ? self::FONT_FAMILY_MONO : self::FONT_FAMILY)
                ->setSize($size)
                ->setBold($run->bold || $bullet->heading)
                ->setItalic($run->italic)
                ->setColor(new Color('FF'.$color));
        }
    }

    private function bulletStyle(SlideBullet $bullet, int $level, PptxPalette $palette): Bullet
    {
        $style = new Bullet();

        return match ($bullet->marker) {
            SlideBulletMarker::None => $style->setBulletType(Bullet::TYPE_NONE),
            SlideBulletMarker::Number => $style
                ->setBulletType(Bullet::TYPE_NUMERIC)
                ->setBulletNumericStyle(Bullet::NUMERIC_ARABICPERIOD)
                ->setBulletNumericStartAt(1)
                ->setBulletColor(new Color('FF'.$palette->accent)),
            SlideBulletMarker::Dot => $style
                ->setBulletType(Bullet::TYPE_BULLET)
                ->setBulletChar(self::BULLET_CHARS[$level])
                ->setBulletFont(self::FONT_FAMILY)
                ->setBulletColor(new Color('FF'.$palette->accent)),
        };
    }

    /**
     * Long slides shrink their type a step rather than overflowing the canvas.
     */
    private function densityScale(int $lines): float
    {
        if ($lines > self::VERY_DENSE_BODY_LINES) {
            return 0.75;
        }

        return $lines > self::DENSE_BODY_LINES ? 0.87 : 1.0;
    }

    private function tableHeight(SlideTable $table): int
    {
        return (1 + count($table->rows)) * self::TABLE_ROW_MAX_HEIGHT;
    }

    private function addTable(Slide $target, SlideTable $table, PptxPalette $palette, int $x, int $y, int $width, int $height): void
    {
        $columns = max(1, min($table->columnCount(), SlideTable::MAX_COLUMNS));
        $rowCount = 1 + count($table->rows);
        $rowHeight = max(
            self::TABLE_ROW_MIN_HEIGHT,
            min(self::TABLE_ROW_MAX_HEIGHT, intdiv($height, $rowCount)),
        );

        $shape = $target->createTableShape($columns);
        $shape->setOffsetX($x)->setOffsetY($y)->setWidth($width);
        // Without an explicit frame height the table would be written as a
        // zero-height shape, which some viewers clip away entirely.
        $shape->setHeight($rowCount * $rowHeight);

        $columnWidth = intdiv($width, $columns);

        $this->addTableRow($shape, $table->headers, $columns, $columnWidth, $rowHeight, $palette, header: true);
        foreach ($table->rows as $row) {
            $this->addTableRow($shape, $row, $columns, $columnWidth, $rowHeight, $palette, header: false);
        }
    }

    /**
     * @param list<string> $cells
     */
    private function addTableRow(Table $shape, array $cells, int $columns, int $columnWidth, int $rowHeight, PptxPalette $palette, bool $header): void
    {
        $row = $shape->createRow();
        $row->setHeight($rowHeight);

        if ($header) {
            $row->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$palette->accent));
        }

        for ($index = 0; $index < $columns; ++$index) {
            $cell = $row->getCell($index);
            $cell->setWidth($columnWidth);

            // Cell borders default to a black box, which would fight the theme.
            $borders = $cell->getBorders();
            foreach ([$borders->getLeft(), $borders->getRight(), $borders->getBottom()] as $border) {
                $border->setLineStyle(Border::LINE_NONE);
            }

            // A horizontal edge is shared between two rows, and the writer takes
            // it from the LOWER cell's top border — so the row separator has to
            // live there, not on the bottom border of the row above it.
            $borders->getTop()
                ->setLineStyle($header ? Border::LINE_NONE : Border::LINE_SINGLE)
                ->setLineWidth(1)
                ->setColor(new Color('FF'.$palette->muted));

            if ($header) {
                $cell->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('FF'.$palette->accent));
            }

            $run = $cell->getActiveParagraph()->createTextRun($cells[$index] ?? '');
            $run->getFont()
                ->setName(self::FONT_FAMILY)
                ->setSize(self::FONT_TABLE)
                ->setBold($header)
                ->setColor(new Color('FF'.($header ? $palette->onAccent : $palette->body)));
        }
    }

    /**
     * Fit the pictures into the given box, keeping their aspect ratio: one fills
     * the box, several share it as rows.
     *
     * @param list<string> $paths
     */
    private function placeImages(Slide $target, array $paths, int $x, int $y, int $width, int $height): void
    {
        $paths = array_slice($paths, 0, self::MAX_IMAGES_PER_SLIDE);
        $count = count($paths);
        if (0 === $count || $width <= 0 || $height <= 0) {
            return;
        }

        $cellHeight = intdiv($height - ($count - 1) * self::IMAGE_GAP, $count);
        if ($cellHeight < self::MIN_ELEMENT_HEIGHT) {
            // Too many pictures for the space: show the ones that still fit.
            $count = max(1, intdiv($height, self::MIN_ELEMENT_HEIGHT + self::IMAGE_GAP));
            $paths = array_slice($paths, 0, $count);
            $cellHeight = intdiv($height - ($count - 1) * self::IMAGE_GAP, $count);
        }

        foreach ($paths as $index => $path) {
            $shape = $target->createDrawingShape();
            $shape->setName('Image '.($index + 1));
            $shape->setPath($path);
            $shape->setWidthAndHeight($width, $cellHeight);

            $cellTop = $y + $index * ($cellHeight + self::IMAGE_GAP);
            $shape->setOffsetX($x + intdiv(max(0, $width - $shape->getWidth()), 2));
            $shape->setOffsetY($cellTop + intdiv(max(0, $cellHeight - $shape->getHeight()), 2));
        }
    }

    /**
     * Absolute paths of the pictures a slide can actually show. A reference
     * without a usable file is skipped and logged: losing one picture is a far
     * better outcome for the user than losing the presentation.
     *
     * @param list<string>          $references
     * @param array<string, string> $images
     *
     * @return list<string>
     */
    private function resolveImages(array $references, array $images): array
    {
        $paths = [];
        foreach ($references as $reference) {
            $path = $images[$reference] ?? null;
            if (null === $path || !is_file($path) || false === @getimagesize($path)) {
                $this->logger->warning('PptxRenderer: skipping unusable slide image reference', [
                    'reference' => $reference,
                ]);
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function addAccentRule(Slide $target, int $x, int $y, PptxPalette $palette): void
    {
        $line = $target->createLineShape($x, $y, $x + self::RULE_WIDTH, $y);
        $line->getBorder()
            ->setLineStyle(Border::LINE_SINGLE)
            ->setLineWidth(self::RULE_THICKNESS)
            ->setColor(new Color('FF'.$palette->accent));
    }

    private function addTextShape(
        Slide $target,
        string $text,
        int $x,
        int $y,
        int $width,
        int $height,
        int $size,
        string $color,
        bool $bold = false,
        string $horizontal = Alignment::HORIZONTAL_LEFT,
    ): void {
        $shape = $target->createRichTextShape();
        $shape->setOffsetX($x)->setOffsetY($y)->setWidth($width)->setHeight($height);
        $shape->setInsetLeft(0)->setInsetRight(0)->setInsetTop(0)->setInsetBottom(0);
        $shape->setWrap(RichText::WRAP_SQUARE);
        $shape->setAutoFit(RichText::AUTOFIT_NORMAL);

        $paragraph = $shape->getActiveParagraph();
        $paragraph->getAlignment()->setHorizontal($horizontal);
        $paragraph->setBulletStyle((new Bullet())->setBulletType(Bullet::TYPE_NONE));

        $run = $paragraph->createTextRun($text);
        $run->getFont()
            ->setName(self::FONT_FAMILY)
            ->setSize($size)
            ->setBold($bold)
            ->setColor(new Color('FF'.$color));
    }

    /**
     * @param list<SlideBullet> $bullets
     */
    private function joinBullets(array $bullets): string
    {
        $parts = [];
        foreach ($bullets as $bullet) {
            $text = trim($bullet->text());
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        return implode(' — ', $parts);
    }
}

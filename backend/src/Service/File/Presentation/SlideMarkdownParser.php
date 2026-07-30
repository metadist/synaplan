<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * Turns the markdown the model produces for a `.pptx` into a structured deck.
 *
 * The old writer flattened every line into one text box, which is why a
 * generated presentation looked like a text dump (#1397). Parsing the markdown
 * first gives the renderer real slide parts — a title, bullets with levels,
 * tables and image references — so the deliverable matches what the user asked
 * for and stays editable in PowerPoint.
 *
 * Everything here is forgiving by design: unknown syntax degrades to a plain
 * paragraph rather than failing the document.
 */
final readonly class SlideMarkdownParser
{
    /**
     * Optional deck-wide settings the model may place anywhere in the content,
     * e.g. `{{PPTX:theme=ocean, transition=fade}}`. Public because every other
     * output format has to strip the directive instead of printing it.
     */
    public const DIRECTIVE_PATTERN = '/\{\{PPTX:([^}]*)}}/i';

    private const IMAGE_MARKER_PATTERN = '/\{\{IMAGE:([a-z]+:\d+)}}/';

    /** A cover slide carries a headline and at most a short standfirst. */
    private const MAX_TITLE_SLIDE_PARAGRAPHS = 2;

    public function parse(string $content): SlideDeck
    {
        [$theme, $transition] = $this->extractDirective($content);
        $content = (string) preg_replace(self::DIRECTIVE_PATTERN, '', $content);

        $slides = $this->parseSlides($content);
        if ([] === $slides) {
            $slides = [new SlideContent(null)];
        }

        $slides[0] = $this->promoteTitleSlide($slides[0]);

        return new SlideDeck($slides, $theme, $transition);
    }

    /**
     * @return array{PptxTheme, SlideTransitionKind}
     */
    private function extractDirective(string $content): array
    {
        if (1 !== preg_match(self::DIRECTIVE_PATTERN, $content, $matches)) {
            return [PptxTheme::Default, SlideTransitionKind::None];
        }

        $settings = [];
        foreach (preg_split('/[,;]/', $matches[1]) ?: [] as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $settings[strtolower(trim($key))] = trim($value);
        }

        return [
            PptxTheme::fromName($settings['theme'] ?? null),
            // Models describe transitions as "animations" as often as not.
            SlideTransitionKind::fromName($settings['transition'] ?? $settings['animation'] ?? null),
        ];
    }

    /**
     * @return list<SlideContent>
     */
    private function parseSlides(string $content): array
    {
        $lines = preg_split('/\R/', trim($content)) ?: [];

        $slides = [];
        $builder = new SlideBuilder();
        $inCodeBlock = false;
        $paragraph = [];

        for ($index = 0; $index < count($lines); ++$index) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if (1 === preg_match('/^(```|~~~)/', $trimmed)) {
                $this->flushParagraph($builder, $paragraph);
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock) {
                $builder->addBullet(new SlideBullet(
                    [new SlideTextRun($line, monospace: true)],
                    marker: SlideBulletMarker::None,
                ));
                continue;
            }

            if ('' === $trimmed) {
                $this->flushParagraph($builder, $paragraph);
                $builder->resetIndents();
                continue;
            }

            // Horizontal rule: the conventional "next slide" break in markdown.
            if (1 === preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $this->flushParagraph($builder, $paragraph);
                $slides = $this->closeSlide($slides, $builder);
                $builder = new SlideBuilder();
                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $heading)) {
                $this->flushParagraph($builder, $paragraph);
                $level = strlen($heading[1]);
                $text = trim($heading[2]);

                if ($level <= 2) {
                    if ($builder->hasTitle() || $builder->hasContent()) {
                        $slides = $this->closeSlide($slides, $builder);
                        $builder = new SlideBuilder();
                    }
                    $builder->setTitle($this->plainText($text, $builder));
                    continue;
                }

                $builder->resetIndents();
                $builder->addBullet(new SlideBullet(
                    $this->parseRuns($this->extractImages($text, $builder), bold: true),
                    marker: SlideBulletMarker::None,
                    heading: true,
                ));
                continue;
            }

            if (1 === preg_match('/^\|(.+)$/', $trimmed)) {
                $this->flushParagraph($builder, $paragraph);
                $index = $this->consumeTable($lines, $index, $builder);
                continue;
            }

            if (1 === preg_match('/^(\s*)([-*+])\s+(.*)$/u', $line, $bullet)) {
                $this->flushParagraph($builder, $paragraph);
                $this->addListItem($builder, $bullet[1], $bullet[3], SlideBulletMarker::Dot);
                continue;
            }

            if (1 === preg_match('/^(\s*)\d+[.)]\s+(.*)$/u', $line, $numbered)) {
                $this->flushParagraph($builder, $paragraph);
                $this->addListItem($builder, $numbered[1], $numbered[2], SlideBulletMarker::Number);
                continue;
            }

            if (1 === preg_match('/^>\s?(.*)$/u', $trimmed, $quote)) {
                $this->flushParagraph($builder, $paragraph);
                $builder->resetIndents();
                $builder->addBullet(new SlideBullet(
                    $this->parseRuns($this->extractImages(trim($quote[1]), $builder), italic: true),
                    marker: SlideBulletMarker::None,
                ));
                continue;
            }

            $remaining = $this->extractImages($trimmed, $builder);
            if ('' !== trim($remaining)) {
                // Wrapped prose belongs to one paragraph, not one per line.
                $paragraph[] = trim($remaining);
            }
        }

        $this->flushParagraph($builder, $paragraph);

        return $this->closeSlide($slides, $builder);
    }

    /**
     * @param list<SlideContent> $slides
     *
     * @return list<SlideContent>
     */
    private function closeSlide(array $slides, SlideBuilder $builder): array
    {
        $slide = $builder->build();
        if (!$slide->isEmpty()) {
            $slides[] = $slide;
        }

        return $slides;
    }

    /**
     * @param list<string> $paragraph
     */
    private function flushParagraph(SlideBuilder $builder, array &$paragraph): void
    {
        if ([] === $paragraph) {
            return;
        }

        $text = implode(' ', $paragraph);
        $paragraph = [];

        $builder->addBullet(new SlideBullet(
            $this->parseRuns($text),
            marker: SlideBulletMarker::None,
        ));
    }

    private function addListItem(SlideBuilder $builder, string $indent, string $text, SlideBulletMarker $marker): void
    {
        $level = $builder->levelForIndent($this->indentWidth($indent));
        $remaining = $this->extractImages(trim($text), $builder);
        if ('' === trim($remaining)) {
            return;
        }

        $builder->addBullet(new SlideBullet($this->parseRuns($remaining), $level, $marker));
    }

    private function indentWidth(string $indent): int
    {
        return strlen(str_replace("\t", '    ', $indent));
    }

    /**
     * Read a markdown table starting at $start and return the index of its last
     * line, so the caller continues after it.
     *
     * @param list<string> $lines
     */
    private function consumeTable(array $lines, int $start, SlideBuilder $builder): int
    {
        $rows = [];
        $last = $start;

        for ($index = $start; $index < count($lines); ++$index) {
            $trimmed = trim($lines[$index]);
            if (1 !== preg_match('/^\|(.+)$/', $trimmed)) {
                break;
            }

            $last = $index;
            // The `|---|---|` alignment row carries no data.
            if (1 === preg_match('/^\|[\s:|-]+$/', $trimmed)) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($trimmed, '|')));
            $rows[] = array_map(
                fn (string $cell): string => $this->plainText($cell, $builder),
                array_slice($cells, 0, SlideTable::MAX_COLUMNS),
            );
        }

        if ([] !== $rows) {
            $headers = array_shift($rows);
            $builder->addTable(new SlideTable($headers, array_slice($rows, 0, SlideTable::MAX_ROWS)));
        }

        return $last;
    }

    /**
     * Collect the image markers of a line and return the line without them. The
     * reference is kept as-is; resolving it to a file is the renderer's job.
     */
    private function extractImages(string $text, SlideBuilder $builder): string
    {
        if (!str_contains($text, '{{IMAGE:')) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::IMAGE_MARKER_PATTERN,
            static function (array $matches) use ($builder): string {
                $builder->addImage($matches[1]);

                return '';
            },
            $text,
        );
    }

    /**
     * Inline markdown reduced to plain text, for places that carry no runs
     * (slide titles, table cells).
     */
    private function plainText(string $text, SlideBuilder $builder): string
    {
        $runs = $this->parseRuns($this->extractImages($text, $builder));

        return trim(implode('', array_map(static fn (SlideTextRun $run): string => $run->text, $runs)));
    }

    /**
     * Split inline markdown into styled runs. Nested emphasis works because the
     * content of a match is parsed again with the outer flags inherited.
     *
     * @return list<SlideTextRun>
     */
    private function parseRuns(string $text, bool $bold = false, bool $italic = false, bool $monospace = false): array
    {
        $text = $this->flattenLinks($text);

        // Longest delimiter first, so ***both*** is not read as **bold** + a
        // stray asterisk. Group order drives the branches below.
        $pattern = '/(\*\*\*|___)(?=\S)(.+?)(?<=\S)\1'          // ***bold italic***
            .'|(\*\*|__)(?=\S)(.+?)(?<=\S)\3'                   // **bold**
            .'|(?<![\w*])(\*|_)(?=\S)(.+?)(?<=\S)\5(?![\w*])'   // *italic*
            .'|`([^`]+)`'                                       // `code`
            .'|~~(?=\S)(.+?)(?<=\S)~~/su';                      // ~~struck~~ (markers dropped)

        $runs = [];
        $offset = 0;

        while ($offset < strlen($text) && 1 === preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            [$whole, $wholeOffset] = $matches[0];

            if ($wholeOffset > $offset) {
                $runs[] = new SlideTextRun(substr($text, $offset, $wholeOffset - $offset), $bold, $italic, $monospace);
            }

            if (null !== ($content = $this->matchedGroup($matches, 2))) {
                $runs = array_merge($runs, $this->parseRuns($content, true, true, $monospace));
            } elseif (null !== ($content = $this->matchedGroup($matches, 4))) {
                $runs = array_merge($runs, $this->parseRuns($content, true, $italic, $monospace));
            } elseif (null !== ($content = $this->matchedGroup($matches, 6))) {
                $runs = array_merge($runs, $this->parseRuns($content, $bold, true, $monospace));
            } elseif (null !== ($content = $this->matchedGroup($matches, 7))) {
                $runs[] = new SlideTextRun($content, $bold, $italic, true);
            } elseif (null !== ($content = $this->matchedGroup($matches, 8))) {
                $runs = array_merge($runs, $this->parseRuns($content, $bold, $italic, $monospace));
            }

            $offset = $wholeOffset + strlen($whole);
        }

        if ($offset < strlen($text)) {
            $runs[] = new SlideTextRun(substr($text, $offset), $bold, $italic, $monospace);
        }

        return array_values(array_filter($runs, static fn (SlideTextRun $run): bool => '' !== $run->text));
    }

    /**
     * The text of an alternative's capture group, or null when that alternative
     * did not match. With PREG_OFFSET_CAPTURE an unmatched group reports an
     * offset of -1, and a trailing one may be missing altogether.
     *
     * @param array<int, array{string, int}> $matches
     */
    private function matchedGroup(array $matches, int $group): ?string
    {
        if (!isset($matches[$group]) || $matches[$group][1] < 0) {
            return null;
        }

        return $matches[$group][0];
    }

    /**
     * `[label](https://…)` becomes the label: a slide shows the wording, and the
     * bare URL would only add noise the audience cannot click anyway.
     */
    private function flattenLinks(string $text): string
    {
        return (string) preg_replace('/!?\[([^\]]*)]\([^)]*\)/u', '$1', $text);
    }

    /**
     * Turn the opening slide into a cover slide when it only carries a headline
     * and maybe a standfirst — a deck that starts with a full bullet list is not
     * a title slide and must keep its content layout.
     */
    private function promoteTitleSlide(SlideContent $slide): SlideContent
    {
        if (null === $slide->title || [] !== $slide->tables) {
            return $slide;
        }

        $paragraphs = 0;
        foreach ($slide->bullets as $bullet) {
            if (SlideBulletMarker::None !== $bullet->marker || $bullet->heading) {
                return $slide;
            }
            ++$paragraphs;
        }

        if ($paragraphs > self::MAX_TITLE_SLIDE_PARAGRAPHS) {
            return $slide;
        }

        return new SlideContent(
            $slide->title,
            $slide->bullets,
            $slide->imageReferences,
            $slide->tables,
            titleSlide: true,
        );
    }
}

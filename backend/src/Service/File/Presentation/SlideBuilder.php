<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * Mutable accumulator for the slide {@see SlideMarkdownParser} is currently
 * reading. Keeps the parser free of bookkeeping and the resulting
 * {@see SlideContent} immutable.
 */
final class SlideBuilder
{
    private ?string $title = null;

    /** @var list<SlideBullet> */
    private array $bullets = [];

    /** @var list<string> */
    private array $images = [];

    /** @var list<SlideTable> */
    private array $tables = [];

    /**
     * Indent widths seen in the current list block, ascending. Mapping distinct
     * widths to consecutive levels keeps the hierarchy right whether the model
     * indents with two spaces, four spaces or tabs.
     *
     * @var list<int>
     */
    private array $indents = [];

    public function setTitle(string $title): void
    {
        $title = trim($title);
        $this->title = '' === $title ? null : $title;
    }

    public function hasTitle(): bool
    {
        return null !== $this->title;
    }

    public function hasContent(): bool
    {
        return [] !== $this->bullets || [] !== $this->images || [] !== $this->tables;
    }

    public function addBullet(SlideBullet $bullet): void
    {
        if (!$bullet->isEmpty()) {
            $this->bullets[] = $bullet;
        }
    }

    public function addTable(SlideTable $table): void
    {
        $this->tables[] = $table;
    }

    public function addImage(string $reference): void
    {
        if (!in_array($reference, $this->images, true)) {
            $this->images[] = $reference;
        }
    }

    /**
     * Start a new list block: the next item defines level 0 again.
     */
    public function resetIndents(): void
    {
        $this->indents = [];
    }

    public function levelForIndent(int $width): int
    {
        while ([] !== $this->indents && $this->indents[count($this->indents) - 1] > $width) {
            array_pop($this->indents);
        }

        if ([] === $this->indents || $this->indents[count($this->indents) - 1] < $width) {
            $this->indents[] = $width;
        }

        return min(count($this->indents) - 1, SlideBullet::MAX_LEVEL);
    }

    public function build(): SlideContent
    {
        return new SlideContent($this->title, $this->bullets, $this->images, $this->tables);
    }
}

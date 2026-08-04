<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * A markdown table found in a slide, rendered as a real PowerPoint table.
 *
 * Models reach for markdown tables on their own because the officemaker prompt
 * asks for markdown; without this the pipe characters would end up as literal
 * bullet text.
 */
final readonly class SlideTable
{
    /** More columns than this stop being readable on a slide. */
    public const MAX_COLUMNS = 6;

    /** As many body rows as fit the slide body at the minimum row height. */
    public const MAX_ROWS = 12;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {
    }

    public function columnCount(): int
    {
        $count = count($this->headers);
        foreach ($this->rows as $row) {
            $count = max($count, count($row));
        }

        return $count;
    }
}

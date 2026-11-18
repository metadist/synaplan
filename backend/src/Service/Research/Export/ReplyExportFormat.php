<?php

declare(strict_types=1);

namespace App\Service\Research\Export;

/**
 * The files a reply can be downloaded as.
 */
enum ReplyExportFormat: string
{
    case Docx = 'docx';
    case Html = 'html';
    case Markdown = 'md';

    public function contentType(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Html => 'text/html; charset=utf-8',
            self::Markdown => 'text/markdown; charset=utf-8',
        };
    }
}

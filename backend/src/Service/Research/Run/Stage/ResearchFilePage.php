<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Entity\File;
use App\Repository\FileRepository;

/** The text of one file, as a page the reader can open. */
final readonly class ResearchFilePage
{
    public function __construct(private FileRepository $files)
    {
    }

    public function open(?int $fileId): ResearchPage
    {
        $file = null !== $fileId ? $this->files->find($fileId) : null;
        $text = $file instanceof File ? $file->getFileText() : '';
        if ('' === trim($text)) {
            return new ResearchPage(true, '', 'There was no text to read.', []);
        }

        return new ResearchPage(false, $text, '', []);
    }
}

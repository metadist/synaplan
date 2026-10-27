<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\User;
use App\Service\File\FileUploadService;
use App\Service\File\UploadOptions;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Keeps a searchable copy of one Notion page in a knowledge folder. A new
 * version replaces the copy in place, so search never sees two of them.
 */
final readonly class NotionPageIndexer
{
    private const PROCESS_LEVEL = 'vectorize';
    private const MAX_NAME = 120;

    public function __construct(private FileUploadService $uploads)
    {
    }

    /**
     * @return int the id of the searchable file
     */
    public function index(User $user, string $pageId, string $title, string $text, string $version, string $folder): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'notion_');
        if (false === $tmp) {
            throw new NotionActionException('Synaplan could not prepare the copy of this page. Nothing was added to search.');
        }
        try {
            file_put_contents($tmp, '# '.$title."\n\n".self::body($text));
            $name = self::fileName($title);
            $upload = new UploadedFile($tmp, $name, 'text/markdown', null, true);
            $result = $this->uploads->uploadBatch([$upload], $user, $folder, self::PROCESS_LEVEL, new UploadOptions(
                source: 'notion',
                originalName: $name,
                sourceId: $pageId,
                sourceEtag: $version,
                overwrite: true,
                keepSynced: true,
            ));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $id = $result['files'][0]['id'] ?? null;
        if (!is_int($id)) {
            throw new NotionActionException('Synaplan could not save the copy of this page. Search still shows the previous copy, if there was one.');
        }

        return $id;
    }

    /**
     * The fetch answer opens with a sentence for the model, not page text.
     */
    public static function body(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, 'Here is the result of')) {
            $newline = strpos($text, "\n");
            $text = false === $newline ? '' : trim(substr($text, $newline + 1));
        }

        return $text;
    }

    private static function fileName(string $title): string
    {
        $name = trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $title));
        $name = '' === $name ? 'Notion page' : mb_substr((string) preg_replace('/\s+/', ' ', $name), 0, self::MAX_NAME);

        return $name.'.md';
    }
}

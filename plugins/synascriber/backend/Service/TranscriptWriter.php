<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use App\Entity\File;
use App\Entity\User;
use App\Service\File\FileUploadService;
use App\Service\File\UploadOptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Saves the transcript as a Markdown file in one person's Synaplan Files:
 * source "generated", kind "document" (the Generated overview, ready to be
 * pushed to OpenCloud / Nextcloud from there), in the session's folder,
 * extracted and vectorized so chat can answer from it. Re-running for the
 * same session overwrites that person's copy instead of adding another.
 */
final readonly class TranscriptWriter
{
    private const SOURCE = 'generated';
    private const ORIGIN_KIND = 'document';

    public function __construct(
        private FileUploadService $uploads,
        private EntityManagerInterface $em,
    ) {
    }

    public function write(User $user, string $ref, string $folder, string $filename, string $markdown): int
    {
        $path = tempnam(sys_get_temp_dir(), 'synascriber-');
        if (false === $path || false === file_put_contents($path, $markdown)) {
            throw new SessionException('file_not_saved', 'The transcript could not be prepared for saving.', 500);
        }

        try {
            $upload = new UploadedFile($path, $filename, 'text/markdown', null, true);
            $options = new UploadOptions(source: self::SOURCE, originalName: $filename, sourceId: 'synascriber-'.$ref, overwrite: true);
            $result = $this->uploads->uploadBatch([$upload], $user, $folder, 'vectorize', $options);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $saved = $result['files'][0] ?? null;
        if (!is_array($saved) || !isset($saved['id'])) {
            $reason = is_array($result['errors'][0] ?? null) ? (string) ($result['errors'][0]['error'] ?? '') : '';
            throw new SessionException('file_not_saved', 'Synaplan did not accept the transcript file: '.$reason, 500);
        }

        $file = $this->em->find(File::class, (int) $saved['id']);
        if ($file instanceof File && self::ORIGIN_KIND !== $file->getOriginKind()) {
            $file->setOriginKind(self::ORIGIN_KIND);
            $this->em->flush();
        }

        return (int) $saved['id'];
    }
}

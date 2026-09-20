<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Compute\Contract\ComputeArtefact;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pull sidecar artefacts into BFILES. Nothing from /out is executed.
 */
final readonly class ComputeArtefactStore
{
    private const MIME_ALLOW = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'text/csv',
        'text/plain',
        'application/json',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private ComputeClient $client,
        private UserUploadPathBuilder $paths,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * @return list<File>
     */
    public function ingest(string $runId, Message $message, int $maxBytes): array
    {
        return $this->ingestForUser($runId, $message->getUserId(), $maxBytes, $message->getId());
    }

    /**
     * @return list<File>
     */
    public function ingestForUser(string $runId, int $userId, int $maxBytes, ?int $messageId = null): array
    {
        $stored = [];
        foreach ($this->client->listArtefacts($runId) as $artefact) {
            $file = $this->storeOne($runId, $artefact, $userId, $maxBytes, $messageId);
            if ($file instanceof File) {
                $stored[] = $file;
            }
        }

        return $stored;
    }

    public function allowsMime(string $mime): bool
    {
        return in_array($mime, self::MIME_ALLOW, true);
    }

    private function storeOne(string $runId, ComputeArtefact $artefact, int $userId, int $maxBytes, ?int $messageId): ?File
    {
        if (null !== $artefact->rejected || !$this->allowsMime($artefact->mime)) {
            $this->logger->info('ComputeArtefactStore: skipped artefact', [
                'name' => $artefact->name,
                'mime' => $artefact->mime,
                'rejected' => $artefact->rejected,
            ]);

            return null;
        }
        if ($artefact->size > $maxBytes) {
            $this->logger->info('ComputeArtefactStore: artefact over size cap', ['name' => $artefact->name]);

            return null;
        }

        $bytes = $this->client->downloadArtefact($runId, $artefact->name);
        if (strlen($bytes) > $maxBytes) {
            return null;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $artefact->name) ?? 'artefact';
        $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION) ?: 'bin');
        $basename = pathinfo($safe, PATHINFO_FILENAME) ?: 'artefact';
        $filename = $basename.'_'.$runId.'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $relative = $this->paths->buildUserBaseRelativePath($userId).'/'.date('Y').'/'.date('m').'/'.$filename;
        $absolute = rtrim($this->uploadDir, '/').'/'.$relative;
        if (!FileHelper::ensureParentDirectory($absolute)) {
            $this->logger->error('ComputeArtefactStore: cannot create directory', ['dir' => dirname($absolute)]);

            return null;
        }
        $written = file_put_contents($absolute, $bytes);
        if (false === $written || $written !== strlen($bytes)) {
            if (is_file($absolute)) {
                unlink($absolute);
            }
            $this->logger->error('ComputeArtefactStore: write failed', ['path' => $relative]);

            return null;
        }

        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($relative);
        $file->setFileType($ext);
        $file->setFileName($filename);
        $file->setFileSize(strlen($bytes));
        $file->setFileMime($artefact->mime);
        $file->setFileText('');
        $file->setSource('compute');
        $file->setOriginKind($this->originKindFor($artefact->mime, $ext));
        $file->setVectorState(File::VECTOR_STATE_NONE);
        $file->setMessageId($messageId);
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    /**
     * Map a harvested artefact to one of {@see File::ORIGIN_KINDS} so it appears
     * under the matching filter chip in the Generated tab (image/video/audio/
     * calendar/document). Compute provenance is already carried by
     * source='compute'; the origin kind describes the media, defaulting to
     * 'document' for anything non-media (CSV, JSON, text, spreadsheets, …).
     */
    private function originKindFor(string $mime, string $ext): string
    {
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if ('text/calendar' === $mime || 'ics' === $ext) {
            return 'calendar';
        }

        return 'document';
    }
}

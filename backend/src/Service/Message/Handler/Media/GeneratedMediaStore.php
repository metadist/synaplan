<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\File\ThumbnailService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Message\ProgressCallback;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 7 of a media turn: keeps what the provider made. The file is saved
 * in the user's upload tree (decoded from a data URL or downloaded), it gets
 * a file row so it shows in the Generated gallery (ephemeral in incognito),
 * a video gets its poster (#1499), and a file the user asked to keep in a
 * folder is delivered there.
 */
final readonly class GeneratedMediaStore
{
    public function __construct(
        private MediaUploadStore $uploadStore,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private ThumbnailService $thumbnailService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDir,
        private ?RequestedFolderDelivery $folderDelivery = null,
    ) {
    }

    /**
     * @param array<string, mixed> $result the provider's answer
     *
     * @throws \Exception when the file cannot be saved
     */
    public function keep(MediaTurnRequest $request, array $result, string $mediaUrl, ?callable $progressCallback): StoredMedia
    {
        $message = $request->message;
        $mediaType = $request->mediaType;
        $provider = (string) $request->model->provider;
        ProgressCallback::notify($progressCallback, 'generating', 'Saving '.$request->mediaTypeLabel().'...');

        if (str_starts_with($mediaUrl, 'data:')) {
            // Data URL from AI provider - decode and save to disk
            // (message id is only used for the filename; 0 for transient
            // incognito messages)
            $localPath = $this->uploadStore->saveDataUrlAsFile($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider);
            if (!$localPath) {
                throw new \Exception("Failed to save generated {$mediaType} data URL to disk");
            }
            $this->logger->info('MediaGenerationHandler: Saved data URL to disk', [
                'path' => $localPath,
                'type' => $mediaType,
            ]);
        } else {
            // External URL - download to disk
            $localPath = $this->uploadStore->downloadMedia($mediaUrl, $message->getId() ?? 0, $message->getUserId(), $provider, $mediaType);
            if (!$localPath) {
                throw new \Exception("Failed to download {$mediaType} from: {$mediaUrl}");
            }
            $this->logger->info('MediaGenerationHandler: Downloaded media to disk', [
                'path' => $localPath,
                'type' => $mediaType,
            ]);
        }

        // The asynchronous path registers through MediaJobMessageSync; the
        // inline path registers here, best-effort and idempotent.
        $registeredFile = $this->generatedFileRegistrar->register(
            $message->getUserId(),
            $localPath,
            $mediaType,
            $message->getId(),
            $result['provider'] ?? $provider,
            ephemeral: !empty($request->options['incognito']),
        );

        $thumbnailPath = null;
        if ('video' === $mediaType) {
            ProgressCallback::notify($progressCallback, 'generating', 'Creating video thumbnail...');
            $thumbnailPath = $this->thumbnailService->generateThumbnail($localPath);
            if ($thumbnailPath) {
                $this->logger->info('MediaGenerationHandler: Video thumbnail generated', [
                    'video' => $localPath,
                    'thumbnail' => $thumbnailPath,
                ]);

                // The poster on the file row lets the Generated grid serve it
                // through GET /api/v1/files/{id}/thumb (#1499).
                if ($registeredFile instanceof File) {
                    $registeredFile->setThumbPath($thumbnailPath);
                    $this->em->flush();
                }
            }
        }

        return new StoredMedia($localPath, '/api/v1/files/uploads/'.$localPath, $thumbnailPath);
    }

    /**
     * The spoken script is kept on the file row, so Files can describe the
     * audio without falling back to the MP3's duration (#1251).
     */
    public function keepSpeech(MediaTurnRequest $request, string $relativePath, ?string $provider): void
    {
        $this->generatedFileRegistrar->register(
            $request->message->getUserId(),
            $relativePath,
            $request->mediaType,
            $request->message->getId(),
            $provider,
            ephemeral: !empty($request->options['incognito']),
            fileText: $request->prompt,
        );
    }

    /**
     * When the user asked to file the result in Nextcloud and the multitask
     * plan did not already take that step, upload the just-generated file.
     *
     * @param array<string, mixed> $options
     */
    public function deliverToFolder(Message $message, string $localPath, array $options): ?string
    {
        if (!empty($options['skip_folder_delivery']) || null === $this->folderDelivery) {
            return null;
        }

        $text = (string) $message->getText();
        if (!$this->folderDelivery->userAskedToSaveToFolder($text)) {
            return null;
        }

        $absolute = $this->uploadDir.'/'.ltrim($localPath, '/');
        if (!is_file($absolute)) {
            return null;
        }

        $result = $this->folderDelivery->send(
            $message->getUserId(),
            [['path' => $absolute, 'name' => basename($localPath)]],
        );

        return $result['message'];
    }
}

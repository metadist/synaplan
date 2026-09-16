<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\File;
use App\Message\GenerateDocumentThumbnailMessage;
use App\Repository\FileRepository;
use App\Service\File\FileHelper;
use App\Service\File\Office\DocumentThumbnailGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateDocumentThumbnailMessageHandler
{
    public function __construct(
        private FileRepository $files,
        private DocumentThumbnailGenerator $generator,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public function __invoke(GenerateDocumentThumbnailMessage $message): void
    {
        $file = $this->files->find($message->fileId);
        if (!$file instanceof File) {
            return;
        }

        $existing = $file->getThumbPath();
        if (null !== $existing && '' !== $existing) {
            $absolute = $this->uploadDir.'/'.ltrim($existing, '/');
            if (FileHelper::fileExistsNfs($absolute)) {
                return;
            }
        }

        $thumb = $this->generator->generate($file);
        if (null === $thumb) {
            return;
        }

        $file->setThumbPath($thumb);
        $this->em->flush();

        $this->logger->info('Document thumbnail stored', [
            'file_id' => $file->getId(),
            'thumb' => $thumb,
        ]);
    }
}

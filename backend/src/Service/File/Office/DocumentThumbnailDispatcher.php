<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use App\Entity\File;
use App\Message\GenerateDocumentThumbnailMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queue a document/PDF poster. Never converts on the HTTP request path.
 */
final readonly class DocumentThumbnailDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private OfficeConverterClient $converter,
    ) {
    }

    public function dispatchIfNeeded(?File $file): void
    {
        if (!$file instanceof File || null === $file->getId()) {
            return;
        }

        $ext = DocumentThumbnailGenerator::extensionOf($file);
        if (DocumentThumbnailGenerator::isPdf($ext)) {
            $this->bus->dispatch(new GenerateDocumentThumbnailMessage($file->getId()));

            return;
        }

        if (DocumentThumbnailGenerator::isOffice($ext) && $this->converter->isEnabled()) {
            $this->bus->dispatch(new GenerateDocumentThumbnailMessage($file->getId()));
        }
    }
}

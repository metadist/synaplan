<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Repository\FileRepository;
use App\Service\Document\DocumentKind;
use App\Service\Document\DocumentModelMerger;
use App\Service\Document\Import\DocumentImporter;

/**
 * True merge on the model (B7): append sheets / blocks / slides.
 */
final class MergeDocumentsTool extends AbstractDocumentTool
{
    public function __construct(
        private FileRepository $fileRepository,
        private DocumentImporter $importer,
        private DocumentModelMerger $merger,
        private string $uploadDir,
    ) {
    }

    public function name(): string
    {
        return 'merge_documents';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Append other documents of the same type into this one.', [
            'fileIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], ['fileIds']);
    }

    public function appliesTo(): array
    {
        return DocumentKind::ALL;
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $ids = $input['fileIds'] ?? [];
        if (!is_array($ids) || [] === $ids) {
            return DocumentToolResult::error('fileIds required', 'processing.documentStepInvalidCells');
        }
        $added = 0;
        foreach ($ids as $id) {
            $file = $this->fileRepository->find((int) $id);
            if (null === $file) {
                continue;
            }
            $absolute = $this->uploadDir.'/'.ltrim($file->getFilePath(), '/');
            $imported = $this->importer->import($absolute, $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION));
            if (null === $imported) {
                continue;
            }
            $added += $this->merger->merge($session->model, $imported['model']);
        }

        return DocumentToolResult::ok(
            sprintf('Merged %d document(s)', $added),
            'processing.documentStepMerge',
            ['count' => $added],
        );
    }
}

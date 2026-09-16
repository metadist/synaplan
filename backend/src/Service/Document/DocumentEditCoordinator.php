<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Repository\FileRepository;
use App\Service\Document\Import\DocumentImporter;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\Render\DocumentRenderer;
use App\Service\Document\Serializer\DocumentModelSerializer;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\File\FileHelper;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Officemaker + DOCUMENT_TOOLS path. Returns null to keep the classic envelope.
 */
final readonly class DocumentEditCoordinator
{
    public function __construct(
        private DocumentToolsConfig $config,
        private ChatToolLoop $loop,
        private DocumentRevisionService $revisions,
        private DocumentImporter $importer,
        private DocumentModelSerializer $serializer,
        private DocumentRenderer $renderer,
        private DocumentTextProjector $projector,
        private FileRepository $fileRepository,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public function shouldRun(string $topic, ?Model $model, Message $message): bool
    {
        if ('officemaker' !== $topic || !$this->config->isEnabled($message->getUserId())) {
            return false;
        }
        if (null === $model || !$model->hasFeature('tool_use')) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>>                                 $messages
     * @param array<string, mixed>                                       $options
     * @param callable(string, string, array<string, mixed>=): void|null $progress
     */
    public function run(
        array $messages,
        Message $message,
        array $options,
        ?callable $progress = null,
        bool $ephemeral = false,
    ): ?DocumentEditResult {
        $opened = $this->openSession($message);
        if (null === $opened) {
            return null;
        }
        [$session, $fidelityLossy] = $opened;

        $onStep = null;
        if (null !== $progress) {
            $onStep = static function (DocumentToolResult $result, int $index) use ($progress): void {
                $progress('document_step', $result->labelKey, [
                    'index' => $index,
                    'labelKey' => $result->labelKey,
                    'labelParams' => $result->labelParams,
                    'ok' => $result->ok,
                ]);
            };
        }

        try {
            $loopResult = $this->loop->run($messages, $session, $options, $message->getUserId(), $onStep);
        } catch (\Throwable $e) {
            $this->logger->warning('DocumentEditCoordinator: loop failed, falling back', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$session->hasMutations()) {
            return new DocumentEditResult($loopResult->content, null, $session->operations, $loopResult->usage, null, $fidelityLossy);
        }

        $wasExisting = null !== $session->fileId;
        $file = $this->persistSession($session, $message, $ephemeral);
        $summary = $this->summarize($session);
        $version = null;
        if (null !== $file) {
            $rev = $this->revisions->append($file, $session->model, $summary);
            $version = $rev->getVersion();
        }
        $content = '' !== trim($loopResult->content) ? $loopResult->content : $summary;
        if (null !== $file && !$wasExisting) {
            $content = '__FILE_GENERATED__:'.$file->getFileName();
        }

        return new DocumentEditResult($content, $file, $session->operations, $loopResult->usage, $version, $fidelityLossy);
    }

    /**
     * @return array{0: DocumentSession, 1: bool}|null
     */
    private function openSession(Message $message): ?array
    {
        $attached = $this->firstOfficeAttachment($message);
        if (null !== $attached) {
            $absolute = $this->uploadDir.'/'.ltrim($attached->getFilePath(), '/');
            $ext = $attached->getFileType() ?: pathinfo($attached->getFileName(), PATHINFO_EXTENSION);
            $latest = $this->revisions->latestFor($attached);
            if (null !== $latest && $this->revisions->binaryMatchesLatest($attached)) {
                try {
                    $model = $this->serializer->decode($latest->getModel());

                    return [new DocumentSession($model, $attached->getId(), $attached->getFileName()), false];
                } catch (\Throwable) {
                    // fall through to importer
                }
            }
            $imported = $this->importer->import($absolute, $ext);
            if (null !== $imported) {
                return [
                    new DocumentSession($imported['model'], $attached->getId(), $attached->getFileName()),
                    $imported['report']->lossy,
                ];
            }

            return null;
        }

        $kind = $this->inferKind((string) $message->getText());

        return [DocumentSession::empty($kind, $this->defaultFilename($kind)), false];
    }

    private function firstOfficeAttachment(Message $message): ?File
    {
        foreach ($message->getFiles() as $file) {
            $ext = $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION);
            if (null !== DocumentKind::fromExtension($ext)) {
                return $file;
            }
        }
        if ($message->getFile() > 0) {
            $file = $this->fileRepository->find($message->getFile());
            if (null !== $file) {
                $ext = $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION);
                if (null !== DocumentKind::fromExtension($ext)) {
                    return $file;
                }
            }
        }

        return null;
    }

    private function inferKind(string $text): string
    {
        if (preg_match('/\b(xlsx|excel|spreadsheet|csv|workbook)\b/i', $text)) {
            return DocumentKind::XLSX;
        }
        if (preg_match('/\b(pptx|powerpoint|slides?|deck|presentation)\b/i', $text)) {
            return DocumentKind::PPTX;
        }
        if (preg_match('/\b(docx|word|document)\b/i', $text)) {
            return DocumentKind::DOCX;
        }

        return DocumentKind::XLSX;
    }

    private function defaultFilename(string $kind): string
    {
        return match ($kind) {
            DocumentKind::DOCX => 'document.docx',
            DocumentKind::PPTX => 'presentation.pptx',
            default => 'workbook.xlsx',
        };
    }

    private function persistSession(DocumentSession $session, Message $message, bool $ephemeral): ?File
    {
        $filename = $session->filename ?? $this->defaultFilename($session->kind());
        if (null !== $session->fileId) {
            $file = $this->fileRepository->find($session->fileId);
            if (null === $file || $file->getUserId() !== $message->getUserId()) {
                return null;
            }
            $this->revisions->writeBinary($file, $session->model);

            return $file;
        }

        $userId = $message->getUserId();
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename;
        $basename = pathinfo($sanitized, PATHINFO_FILENAME);
        $relative = $userBase.'/'.date('Y').'/'.date('m').'/'.$basename.'_'.time().'.'.$session->kind();
        $absolute = $this->uploadDir.'/'.$relative;
        if (!FileHelper::ensureParentDirectory($absolute)) {
            return null;
        }
        $this->renderer->render($session->model, $absolute);
        FileHelper::setFilePermissions($absolute);
        $size = filesize($absolute);
        if (false === $size) {
            return null;
        }
        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($relative);
        $file->setFileType($session->kind());
        $file->setFileName($filename);
        $file->setFileSize($size);
        $file->setFileMime(GeneratedDocumentStore::mimeTypeForExtension($session->kind()));
        $file->setFileText($this->projector->project($session->model));
        $file->setStatus('generated');
        $file->setSource('generated');
        $file->setOriginKind('document');
        $file->setEphemeral($ephemeral);
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    private function summarize(DocumentSession $session): string
    {
        $ok = [];
        foreach ($session->operations as $op) {
            if ($op->ok) {
                $ok[] = $op->message;
            }
        }

        return [] === $ok ? 'Document updated.' : implode('. ', array_slice($ok, 0, 8));
    }
}

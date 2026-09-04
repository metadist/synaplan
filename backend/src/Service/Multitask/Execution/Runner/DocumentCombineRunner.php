<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\Office\DocumentCombineException;
use App\Service\File\Office\DocumentCombineService;
use App\Service\File\Office\DocumentThumbnailGenerator;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;

/**
 * `document_combine` runner — merges two or more office/PDF files that already
 * exist in the conversation into one PDF (#1694).
 *
 * "führe beide dateien in eine pdf zusammen" used to be forced onto
 * `analyzefile`: the model claimed a named PDF existed and no file was stored.
 * This node runs the exact merge the file chip's Combine action runs —
 * {@see DocumentCombineService} — and picks no model.
 *
 * Node contract:
 *   - params.files / inputs.files  optional list of `file:ID` references
 *   - params.filename              optional product title (without needing .pdf)
 *   - output                       one PDF file descriptor + `document_combine`
 *                                  metadata (source ids and the new file id)
 */
final readonly class DocumentCombineRunner implements TaskRunner
{
    private const SERVE_PREFIX = '/api/v1/files/uploads/';

    public function __construct(
        private DocumentCombineService $combineService,
        private ConversationFileCatalog $conversationFiles,
        private FileRepository $files,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::DocumentCombine];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::DocumentCombine,
                'Merge two or more office/PDF files that ALREADY EXIST in this conversation into one PDF. The server concatenates the ORIGINAL files (same merge as the file-chip Combine action) — nothing is re-written. Use it for "merge these into one PDF", "führe beide dateien in eine pdf zusammen", "combine the attachments as PDF". Optional params.files: `file:ID` references (defaults to the files the user attached on this turn). Optional params.filename: the product title. A single existing file exported as PDF is document_export. Creating a NEW document delivered as PDF stays document_generation.',
                available: fn (): bool => $this->serviceWired(),
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        if (!$this->serviceWired()) {
            return NodeResult::failed('document_combine: combine service is not available');
        }

        $catalog = $this->conversationFiles->build($context->message, $context->thread);
        $entries = $this->requestedEntries($node, $context, $catalog);
        if (count($entries) < 2) {
            $entries = $this->defaultEntries($catalog);
        }
        if (count($entries) < 2) {
            return NodeResult::failed('document_combine: need at least two office or PDF files to merge');
        }

        $sources = [];
        foreach ($entries as $entry) {
            $source = $this->loadFile($entry, $context);
            if (null === $source || null === $source->getId()) {
                return NodeResult::failed('document_combine: file "'.$entry->displayName.'" is not available');
            }
            $sources[] = $source;
        }

        $userId = (int) ($context->userId ?? $context->message->getUserId());
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return NodeResult::failed('document_combine: user not found');
        }

        $filename = is_string($node->params['filename'] ?? null) ? $node->params['filename'] : null;

        try {
            $combined = $this->combineService->combineToPdf(
                $user,
                array_map(static fn (File $file): int => (int) $file->getId(), $sources),
                $filename,
            );
        } catch (DocumentCombineException $e) {
            return NodeResult::failed('document_combine: '.$e->getMessage());
        }

        $this->logger->info('DocumentCombineRunner: combined conversation files to PDF', [
            'node_id' => $node->id,
            'source_file_ids' => array_map(static fn (File $file): int => (int) $file->getId(), $sources),
            'pdf_file_id' => $combined->getId(),
        ]);

        $relative = ltrim($combined->getFilePath(), '/');

        return NodeResult::ok(
            'Combined PDF created: '.$combined->getFileName(),
            [[
                'path' => self::SERVE_PREFIX.$relative,
                'type' => 'document',
                'local_path' => $relative,
            ]],
            [
                'media_type' => 'document',
                'document_combine' => [
                    'source_file_ids' => array_map(static fn (File $file): int => (int) $file->getId(), $sources),
                    'pdf_file_id' => $combined->getId(),
                    'filename' => $combined->getFileName(),
                ],
            ],
        );
    }

    /**
     * @param list<ConversationFile> $catalog
     *
     * @return list<ConversationFile>
     */
    private function requestedEntries(TaskNode $node, NodeContext $context, array $catalog): array
    {
        $inputs = $context->resolveInputs($node);
        $requested = $node->params['files'] ?? $inputs['files'] ?? null;
        if (!is_array($requested)) {
            $single = $node->params['file'] ?? $inputs['file'] ?? null;
            $requested = null !== $single ? [$single] : [];
        }

        $entries = [];
        foreach ($requested as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $entry = $this->conversationFiles->findByReference($catalog, $item);
                if (null !== $entry && self::isCombinable($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Current attachments first (the files the user just handed over), then
     * other combinable conversation files. Two attached office/PDF files is
     * the #1694 reproduction.
     *
     * @param list<ConversationFile> $catalog
     *
     * @return list<ConversationFile>
     */
    private function defaultEntries(array $catalog): array
    {
        $attached = [];
        $rest = [];
        foreach ($catalog as $entry) {
            if (!self::isCombinable($entry)) {
                continue;
            }
            if (ConversationFile::ORIGIN_ATTACHED === $entry->origin) {
                $attached[] = $entry;
            } else {
                $rest[] = $entry;
            }
        }

        return count($attached) >= 2 ? $attached : array_merge($attached, $rest);
    }

    private static function isCombinable(ConversationFile $entry): bool
    {
        if (ConversationFile::CATEGORY_DOCUMENT !== $entry->category) {
            return false;
        }

        $ext = strtolower(pathinfo($entry->relativePath, PATHINFO_EXTENSION));

        return DocumentThumbnailGenerator::isOffice($ext) || DocumentThumbnailGenerator::isPdf($ext);
    }

    private function loadFile(ConversationFile $entry, NodeContext $context): ?File
    {
        $userId = (int) ($context->userId ?? $context->message->getUserId());

        if (null !== $entry->fileId) {
            $file = $this->files->find($entry->fileId);

            return $file instanceof File && $file->getUserId() === $userId ? $file : null;
        }

        if (1 === preg_match('/^attached:(\d+)$/', $entry->reference, $m)) {
            $attachments = $this->conversationFiles->attachments($context->message);

            return $attachments[(int) $m[1] - 1] ?? null;
        }

        return null;
    }

    /**
     * SkillCatalogFactory builds runners without constructors for DB-free
     * planner-prompt tests; an unwired runner has nothing to offer.
     */
    private function serviceWired(): bool
    {
        return (new \ReflectionProperty($this, 'combineService'))->isInitialized($this);
    }
}

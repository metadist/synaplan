<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\File;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\RateLimitService;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `file_ingest` on Synaplan's own MCP server. Same steps as the factory
 * closure had: store, index, and roll back when indexing fails.
 */
final readonly class FileIngestExecutor implements ToolExecutorInterface
{
    public function __construct(
        private FileStorageService $storage,
        private StorageQuotaService $quota,
        private VectorizationService $vectors,
        private EntityManagerInterface $em,
        private RateLimitService $rates,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Plugin === $tool->source && 'file_ingest' === $tool->name;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        unset($tool);
        $user = $invocation->user;
        if (!$user instanceof User) {
            return $this->fail('File ingest needs a signed-in account.');
        }
        $content = is_string($invocation->arguments['content'] ?? null) ? $invocation->arguments['content'] : '';
        if ('' === trim($content)) {
            return $this->fail('content must not be empty.');
        }
        if (!($this->rates->checkLimit($user, 'FILE_ANALYSIS')['allowed'] ?? false)) {
            return $this->fail('Rate limit exceeded for file ingestion.');
        }

        $userId = (int) $user->getId();
        try {
            $this->quota->checkStorageLimit($user, \strlen($content));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $name = is_string($invocation->arguments['name'] ?? null) ? $invocation->arguments['name'] : '';
        $title = '' !== trim($name) ? trim($name) : 'mcp-document';
        $groupKey = is_string($invocation->arguments['group_key'] ?? null) ? $invocation->arguments['group_key'] : null;
        $stored = $this->storage->storeRawContent($content, $userId, $title.'.txt', 'text/plain');
        if (!$stored['success']) {
            return $this->fail($stored['error'] ?? 'Failed to store content.');
        }

        $file = (new File())
            ->setUserId($userId)
            ->setFilePath((string) $stored['path'])
            ->setFileType('txt')
            ->setFileName($title)
            ->setFileSize((int) $stored['size'])
            ->setFileMime('text/plain')
            ->setFileText($content)
            ->setGroupKey($groupKey)
            ->setStatus('extracted');
        $this->em->persist($file);
        $this->em->flush();

        $vector = $this->vectors->vectorizeAndStore(
            $content,
            $userId,
            (int) $file->getId(),
            $groupKey ?? '',
            FileHelper::getFileTypeCode('txt'),
        );
        if (!($vector['success'] ?? false)) {
            $this->storage->deleteFile((string) $stored['path']);
            $this->em->remove($file);
            $this->em->flush();
            $error = $vector['error'] ?? 'Vectorization failed.';

            return $this->fail(is_string($error) ? $error : 'Vectorization failed.');
        }

        $file->markVectorized();
        $this->em->flush();
        $this->rates->recordFileAnalysisOnce($user, (int) $file->getId(), [
            'source' => 'MCP',
            'filename' => $title,
        ]);

        return new ToolExecution('File added to the knowledge base.', false, 'plugin', 'write', details: [
            'payload' => [
                'success' => true,
                'file_id' => $file->getId(),
                'name' => $title,
                'group_key' => $groupKey,
                'chunks_created' => $vector['chunks_created'] ?? 0,
                'provider' => $vector['provider'] ?? null,
            ],
        ]);
    }

    private function fail(string $text): ToolExecution
    {
        return new ToolExecution($text, true, 'plugin', 'write');
    }
}

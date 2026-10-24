<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Repository\FileRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\StepFiles;
use App\Service\Multitask\Plan\TaskNode;

/**
 * The files a file-work step mounts into the sandbox: the ids the plan
 * named, or else the turn's own attachments together with the stored files
 * the step works on ({@see StepFiles}).
 */
final readonly class CodeRunInputFiles
{
    private const MAX_LEGACY_FILES = 20;

    public function __construct(
        private FileRepository $files,
    ) {
    }

    /**
     * @param array<string, mixed> $inputs the step's resolved inputs
     *
     * @return list<int>
     */
    public function ids(TaskNode $node, NodeContext $context, array $inputs, int $userId): array
    {
        $ids = [];
        foreach ($node->params['inputFileIds'] ?? [] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ([] !== $ids) {
            return $ids;
        }

        return array_values(array_unique([
            ...$this->attachments($context, $userId),
            ...StepFiles::storedIds(StepFiles::of($node, $context, $inputs)),
        ]));
    }

    /**
     * The planner references attachments as `$message.files` and cannot know
     * their numeric ids, so an interactive "run code on the attached file"
     * turn arrives with no inputFileIds. The message's own attachments are
     * mounted by name instead: the ManyToMany attachments (web chat) and the
     * legacy single-file column (channel messages, File.messageId).
     *
     * @return list<int>
     */
    private function attachments(NodeContext $context, int $userId): array
    {
        $ids = [];
        foreach ($context->message->getFiles() as $file) {
            $fileId = $file->getId();
            if (null !== $fileId) {
                $ids[] = (int) $fileId;
            }
        }
        $messageId = $context->message->getId();
        if ([] === $ids && $context->message->getFile() > 0 && null !== $messageId) {
            foreach ($this->files->findFilesByMessageIds($userId, [$messageId], self::MAX_LEGACY_FILES) as $file) {
                $fileId = $file->getId();
                if (null !== $fileId) {
                    $ids[] = (int) $fileId;
                }
            }
        }

        return $ids;
    }
}

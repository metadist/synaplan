<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Message;
use App\Service\Multitask\Execution\InputFileText;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\StepFiles;
use App\Service\Multitask\Plan\TaskNode;

/**
 * Appends the connected-folder read honesty block to chat system prompts.
 */
final readonly class ConnectedFolderReadHonestyPrompt
{
    public function __construct(
        private ConnectedFolderContentGuard $guard,
        private ConnectedFolderGuardCatalog $folderCatalog,
        private InputFileText $inputFileText,
    ) {
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    public function forChatHandler(Message $message, array $thread): string
    {
        $userId = (int) $message->getUserId();
        if ($userId <= 0) {
            return '';
        }

        $userText = (string) $message->getText();
        $sources = $this->folderCatalog->folderSourcesForUser($userId);
        if (!$this->guard->demandsFolderFileContent($userText, $sources)) {
            return '';
        }
        if ($this->guard->hasExtractedFileTextOnMessage($message)) {
            return '';
        }

        return ConnectedFolderContentGuard::instructionBlock();
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function forChatRunner(
        TaskNode $node,
        NodeContext $context,
        array $inputs,
    ): string {
        $userId = $context->userId ?? (int) $context->message->getUserId();
        if ($userId <= 0) {
            return '';
        }

        $userText = (string) $context->message->getText();
        $sources = $this->folderCatalog->folderSourcesForUser($userId);
        if (!$this->guard->demandsFolderFileContent($userText, $sources)) {
            return '';
        }
        if ($this->guard->hasExtractedFileTextOnMessage($context->message)) {
            return '';
        }
        if ($this->hasExtractedFolderContentForRunner($node, $context, $inputs, $userId)) {
            return '';
        }

        return ConnectedFolderContentGuard::instructionBlock();
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function hasExtractedFolderContentForRunner(
        TaskNode $node,
        NodeContext $context,
        array $inputs,
        int $userId,
    ): bool {
        foreach ($node->dependsOn as $dependencyId) {
            $result = $context->getResult($dependencyId);
            if (null === $result || !$result->isSuccessful()) {
                continue;
            }
            if (true !== ($result->metadata['folder_fetch'] ?? false) && '' !== trim($result->text)) {
                return true;
            }
            if (true === ($result->metadata['folder_fetch'] ?? false)) {
                foreach ($result->files as $descriptor) {
                    if (!is_array($descriptor)) {
                        continue;
                    }
                    if ('' !== trim($this->inputFileText->of($descriptor, $userId))) {
                        return true;
                    }
                }
            }
        }

        foreach (StepFiles::of($node, $context, $inputs) as $descriptor) {
            if ('' !== trim($this->inputFileText->of($descriptor, $userId))) {
                return true;
            }
        }

        return false;
    }
}

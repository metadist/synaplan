<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * Parses BREQUESTEDBY into a structured reference the frontend never splits.
 */
final readonly class ApprovalReference
{
    public const KIND_CHAT = 'chat';
    public const KIND_TASK_RUN = 'task_run';

    public function __construct(
        public string $kind,
        public string $raw,
        public ?int $messageId = null,
        public ?int $runId = null,
        public ?string $nodeId = null,
        public ?int $chatId = null,
        public ?int $taskId = null,
    ) {
    }

    public static function chat(int $messageId): self
    {
        return new self(self::KIND_CHAT, 'chat:'.$messageId, messageId: $messageId);
    }

    public static function taskRun(int $runId, string $nodeId): self
    {
        return new self(self::KIND_TASK_RUN, sprintf('task_run:%d:%s', $runId, $nodeId), runId: $runId, nodeId: $nodeId);
    }

    public static function parse(string $requestedBy): self
    {
        if (str_starts_with($requestedBy, 'chat:')) {
            $id = substr($requestedBy, 5);

            return new self(self::KIND_CHAT, $requestedBy, messageId: ctype_digit($id) ? (int) $id : null);
        }
        if (str_starts_with($requestedBy, 'task_run:')) {
            $rest = substr($requestedBy, 9);
            $parts = explode(':', $rest, 2);
            $runId = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : null;
            $nodeId = $parts[1] ?? null;

            return new self(self::KIND_TASK_RUN, $requestedBy, runId: $runId, nodeId: $nodeId);
        }

        return new self('unknown', $requestedBy);
    }

    /**
     * @return array{kind: string, chatId: int|null, messageId: int|null, taskId: int|null, runId: int|null, nodeId: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'taskId' => $this->taskId,
            'runId' => $this->runId,
            'nodeId' => $this->nodeId,
        ];
    }

    public function withChatId(?int $chatId): self
    {
        return new self($this->kind, $this->raw, $this->messageId, $this->runId, $this->nodeId, $chatId, $this->taskId);
    }

    public function withTaskId(?int $taskId): self
    {
        return new self($this->kind, $this->raw, $this->messageId, $this->runId, $this->nodeId, $this->chatId, $taskId);
    }
}

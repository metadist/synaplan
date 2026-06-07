<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Entity\Message;
use App\Service\Multitask\Plan\TaskNode;

/**
 * Execution context shared across a plan's nodes.
 *
 * Holds the inbound message, thread, resolved user id, the base classification,
 * processing options, and the accumulated per-node results. Resolves a node's
 * typed input references against the message and upstream node outputs.
 *
 * Reference grammar (string values in a node's `inputs`):
 *   - "$message.text"      → inbound message text
 *   - "$message.fileText"  → inbound extracted file text
 *   - "$message.files"     → inbound file descriptors (list)
 *   - "$nX.text"           → upstream node X text
 *   - "$nX.file"           → upstream node X first file descriptor
 *   - "$nX.files"          → upstream node X all file descriptors
 *   - anything else        → literal value (passed through unchanged)
 *   - a list of the above   → resolved element-wise
 */
final class NodeContext
{
    /** @var array<string, NodeResult> nodeId => result */
    private array $results = [];

    /**
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly Message $message,
        public readonly array $thread,
        public readonly ?int $userId,
        public readonly array $classification,
        public readonly array $options = [],
    ) {
    }

    public function setResult(string $nodeId, NodeResult $result): void
    {
        $this->results[$nodeId] = $result;
    }

    public function getResult(string $nodeId): ?NodeResult
    {
        return $this->results[$nodeId] ?? null;
    }

    /**
     * @return array<string, NodeResult>
     */
    public function allResults(): array
    {
        return $this->results;
    }

    /**
     * Resolve every input reference of a node into concrete values.
     *
     * @return array<string, mixed>
     */
    public function resolveInputs(TaskNode $node): array
    {
        $resolved = [];
        foreach ($node->inputs as $key => $value) {
            $resolved[$key] = $this->resolve($value);
        }

        return $resolved;
    }

    /**
     * Resolve a single input value (scalar ref, literal, or list of refs).
     */
    public function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolve($item), $value);
        }

        if (!is_string($value) || !str_starts_with($value, '$')) {
            return $value; // literal
        }

        return match (true) {
            '$message.text' === $value => $this->message->getText(),
            '$message.fileText' === $value => $this->message->getFileText() ?: '',
            '$message.files' === $value => $this->messageFiles(),
            default => $this->resolveNodeRef($value),
        };
    }

    private function resolveNodeRef(string $ref): mixed
    {
        if (1 !== preg_match('/^\$(?<id>[A-Za-z0-9_]+)\.(?<field>text|file|files)$/', $ref, $m)) {
            return null; // unknown reference shape → null (runner decides fallback)
        }

        $result = $this->results[$m['id']] ?? null;
        if (null === $result) {
            return null;
        }

        return match ($m['field']) {
            'text' => $result->text,
            'file' => $result->firstFile(),
            default => $result->files, // 'files' — regex constrains field to text|file|files
        };
    }

    /**
     * Inbound message file descriptors (legacy single file + M2M attachments).
     *
     * @return list<array<string, mixed>>
     */
    private function messageFiles(): array
    {
        $files = [];
        foreach ($this->message->getFiles() as $file) {
            $files[] = [
                'path' => $file->getFilePath(),
                'type' => $file->getFileType() ?: $file->getFileMime(),
                'text' => $file->getFileText() ?: '',
            ];
        }

        if ([] === $files && $this->message->getFile() > 0 && '' !== (string) $this->message->getFilePath()) {
            $files[] = [
                'path' => $this->message->getFilePath(),
                'type' => $this->message->getFileType(),
                'text' => $this->message->getFileText() ?: '',
            ];
        }

        return $files;
    }
}

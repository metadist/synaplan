<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

/**
 * The output of running one DAG node.
 *
 * - `text`  : the node's textual output, if any (consumed via `$nX.text`).
 * - `files` : produced file descriptors, each `['path' => string, 'type' => string,
 *             'local_path' => ?string]` (matching handler `metadata['file']`),
 *             consumed via `$nX.file` (first) or `$nX.files` (all).
 * - `metadata`: passthrough handler metadata (provider/model/usage/…).
 */
final readonly class NodeResult
{
    /**
     * @param list<array<string, mixed>> $files
     * @param array<string, mixed>       $metadata
     */
    public function __construct(
        public NodeStatus $status,
        public ?string $text = null,
        public array $files = [],
        public array $metadata = [],
        public ?string $error = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param array<string, mixed>       $metadata
     */
    public static function ok(?string $text = null, array $files = [], array $metadata = []): self
    {
        return new self(NodeStatus::Done, $text, $files, $metadata);
    }

    public static function failed(string $error): self
    {
        return new self(NodeStatus::Failed, error: $error);
    }

    public static function skipped(string $reason): self
    {
        return new self(NodeStatus::Skipped, error: $reason);
    }

    public function firstFile(): ?array
    {
        return $this->files[0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return NodeStatus::Done === $this->status;
    }
}

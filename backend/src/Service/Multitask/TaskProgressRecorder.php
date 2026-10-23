<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Execution\CardTrace;

/**
 * Mirrors a running plan's events into BMESSAGE_TASKS (#1142 / #1343), so a
 * reload during the turn rebuilds the cards the live stream showed. Terminal
 * `task_update` rows store the settled card body; `task_chunk` / `task_file`
 * accumulate content for it; a folder read's live count is kept on its running
 * card. Every event is forwarded to the client first and unchanged:
 * persistence is a side effect that must never alter or drop the stream.
 */
final class TaskProgressRecorder
{
    private const TERMINAL = ['done', 'failed', 'skipped', 'waiting_approval'];

    /** @var array<string, string> */
    private array $chunkText = [];

    /** @var array<string, string> */
    private array $fileUrls = [];

    /** @var array<string, string> */
    private array $fileTypes = [];

    public function __construct(
        private readonly TaskPlanStore $store,
        private readonly int $messageId,
        private readonly \Closure $forward,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function __invoke(array $event): void
    {
        ($this->forward)($event);

        $status = $event['status'] ?? null;
        $metadata = $event['metadata'] ?? null;
        if (!is_array($metadata)) {
            return;
        }
        $nodeId = $metadata['node_id'] ?? null;
        if (!is_string($nodeId) || '' === $nodeId) {
            return;
        }

        match ($status) {
            'task_chunk' => $this->chunk($nodeId, $metadata),
            'task_file' => $this->file($nodeId, $metadata),
            'task_progress' => $this->progress($nodeId, $metadata),
            'task_update' => $this->update($nodeId, $metadata),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function chunk(string $nodeId, array $metadata): void
    {
        $chunk = $metadata['chunk'] ?? null;
        if (is_string($chunk) && '' !== $chunk) {
            $this->chunkText[$nodeId] = ($this->chunkText[$nodeId] ?? '').$chunk;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function file(string $nodeId, array $metadata): void
    {
        $url = $metadata['url'] ?? null;
        if (is_string($url) && '' !== $url) {
            $this->fileUrls[$nodeId] = $url;
        }
        $type = $metadata['type'] ?? null;
        if (is_string($type) && '' !== $type) {
            $this->fileTypes[$nodeId] = $type;
        }
    }

    /**
     * A render's percentage is not kept; a folder read's "n of m" is, with
     * where it reads from, so the reloaded card does not lose its source.
     *
     * @param array<string, mixed> $metadata
     */
    private function progress(string $nodeId, array $metadata): void
    {
        if (is_array($metadata['folder_read'] ?? null)) {
            $this->store->updateNodeStatus($this->messageId, $nodeId, 'running', CardTrace::fromArray(['folder_read' => $metadata['folder_read']]));
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function update(string $nodeId, array $metadata): void
    {
        $state = $metadata['state'] ?? null;
        if (!is_string($state) || !in_array($state, self::TERMINAL, true)) {
            return;
        }

        $this->store->updateNodeStatus($this->messageId, $nodeId, $state, [
            'text' => $this->stringOr($metadata, 'text', $this->chunkText[$nodeId] ?? null),
            'url' => $this->stringOr($metadata, 'url', $this->fileUrls[$nodeId] ?? null),
            'type' => $this->stringOr($metadata, 'type', $this->fileTypes[$nodeId] ?? null),
            'error' => $this->stringOr($metadata, 'error', null),
            'query' => $this->stringOr($metadata, 'query', null),
            'resultsCount' => is_int($metadata['results_count'] ?? null) ? $metadata['results_count'] : null,
            ...CardTrace::fromArray($metadata),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function stringOr(array $metadata, string $key, ?string $fallback): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : $fallback;
    }
}

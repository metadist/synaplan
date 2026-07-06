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
 *
 * When a string is NOT a pure reference (does not start with `$`) but contains
 * embedded `$nX.text` or `$message.text` tokens, those tokens are interpolated
 * in-place. This prevents literal placeholder text (e.g. "Summary: $n1.text")
 * from leaking into the persisted reply when the planner emits prose with
 * embedded references instead of a pure `"$n1.text"` value.
 */
final class NodeContext
{
    /** @var array<string, NodeResult> nodeId => result */
    private array $results = [];

    /** @var (callable(string, string): void)|null sink for streamed token chunks: (nodeId, chunk) */
    private $chunkSink;

    /** @var (callable(string, array<string, mixed>): void)|null sink for live media progress: (nodeId, progress) */
    private $progressSink;

    private ?string $currentNodeId = null;

    /**
     * Ids of media-generation nodes that MUST render synchronously in-turn
     * because another node depends on their file output (a downstream
     * `file_analysis` reads the bytes, `compose_reply` attaches them). Async
     * detach would leave those dependents blocked forever (#1218).
     *
     * @var array<string, true>
     */
    private array $inlineMediaNodeIds = [];

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     *                                                                                   Message entities in-process; plain `{role, content}`
     *                                                                                   snapshots inside a media-node subprocess (entities cannot
     *                                                                                   cross the process boundary — handlers accept both shapes)
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param list<string>                                             $planCapabilities capability string values of ALL nodes in this plan
     *                                                                                   (sibling awareness — lets a runner know that, e.g.,
     *                                                                                   a `text2sound` step will handle the audio so a `chat`
     *                                                                                   node must not refuse/own that part)
     */
    public function __construct(
        public readonly Message $message,
        public readonly array $thread,
        public readonly ?int $userId,
        public readonly array $classification,
        public readonly array $options = [],
        public readonly array $planCapabilities = [],
    ) {
    }

    /**
     * Register a sink for streamed token chunks. The executor wires this to emit
     * `task_chunk` SSE events tagged with the running node id.
     *
     * @param (callable(string, string): void)|null $sink
     */
    public function setChunkSink(?callable $sink): void
    {
        $this->chunkSink = $sink;
    }

    /** Mark which node is currently executing (so streamed chunks are tagged). */
    public function beginNode(string $nodeId): void
    {
        $this->currentNodeId = $nodeId;
    }

    /** Forward a streamed token chunk for the current node, if a sink is set. */
    public function streamChunk(string $chunk): void
    {
        if (null !== $this->chunkSink && null !== $this->currentNodeId && '' !== $chunk) {
            ($this->chunkSink)($this->currentNodeId, $chunk);
        }
    }

    /**
     * Register a sink for live media-generation progress. The executor wires this
     * to emit `task_progress` SSE events tagged with the node id.
     *
     * @param (callable(string, array<string, mixed>): void)|null $sink
     */
    public function setProgressSink(?callable $sink): void
    {
        $this->progressSink = $sink;
    }

    /**
     * Forward a live progress update for a node (e.g. video render status), if a
     * sink is set.
     *
     * @param array<string, mixed> $progress
     */
    public function emitProgress(string $nodeId, array $progress): void
    {
        if (null !== $this->progressSink && '' !== $nodeId) {
            ($this->progressSink)($nodeId, $progress);
        }
    }

    /**
     * Register the media-generation node ids that must render inline (blocking)
     * instead of detaching to an async job — see {@see $inlineMediaNodeIds}.
     *
     * @param list<string> $nodeIds
     */
    public function setInlineMediaNodeIds(array $nodeIds): void
    {
        $this->inlineMediaNodeIds = array_fill_keys($nodeIds, true);
    }

    /**
     * Whether the given media node has a dependent and therefore must generate
     * synchronously so the produced file is available to it in the same turn.
     */
    public function mustRunMediaInline(string $nodeId): bool
    {
        return isset($this->inlineMediaNodeIds[$nodeId]);
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

        if (!is_string($value)) {
            return $value; // literal (non-string)
        }

        // Pure reference: entire string is exactly one "$message.xxx" or "$nX.field"
        // token with no surrounding prose — return the typed value as-is (may be null,
        // a string, or a file list). Must match from ^ to $ so that a multi-token prose
        // string like "$n1.text and also $n2.text" falls through to interpolateRefs.
        if (1 === preg_match('/^\$(?:message\.(text|fileText|files)|[A-Za-z0-9_]+\.(text|file|files))$/', $value)) {
            return match (true) {
                '$message.text' === $value => $this->message->getText(),
                '$message.fileText' === $value => $this->message->getFileText() ?: '',
                '$message.files' === $value => $this->messageFiles(),
                default => $this->resolveNodeRef($value),
            };
        }

        // Prose interpolation: the planner may embed a reference token inside a
        // larger string (e.g. "Zusammenfassung: $n1.text"). Replace every recognised
        // token in-place so no literal placeholder leaks into the persisted reply.
        if (str_contains($value, '$')) {
            return $this->interpolateRefs($value);
        }

        return $value; // plain literal
    }

    /**
     * Inline-replace every `$nX.text` / `$message.text` / `$message.fileText`
     * token inside a prose string. Unknown or unresolvable references are
     * replaced with an empty string so no placeholder leaks to the user.
     */
    private function interpolateRefs(string $value): string
    {
        return preg_replace_callback(
            '/\$(?:message\.(text|fileText)|([A-Za-z0-9_]+)\.(text))/',
            function (array $m): string {
                // Group 1 is non-empty when the match is $message.text or $message.fileText.
                if ('' !== $m[1]) {
                    $resolved = '$message.text' === $m[0]
                        ? $this->message->getText()
                        : ($this->message->getFileText() ?: '');
                } else {
                    $resolved = $this->resolveNodeRef('$'.$m[2].'.'.$m[3]);
                }

                return is_string($resolved) ? $resolved : '';
            },
            $value
        ) ?? $value;
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

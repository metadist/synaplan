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
    private const SKIPPED_AFTER_FAILURE = [
        'en' => 'Skipped because an earlier step did not finish.',
        'de' => 'Übersprungen, weil ein vorheriger Schritt nicht fertig wurde.',
        'es' => 'Se omitió porque un paso anterior no terminó.',
        'fr' => 'Ignorée, car une étape précédente ne s’est pas terminée.',
        'tr' => 'Önceki bir adım tamamlanmadığı için atlandı.',
    ];

    private const SKIPPED_BY_CONDITION = [
        'en' => 'Skipped because the condition was not met.',
        'de' => 'Übersprungen, weil die Bedingung nicht erfüllt war.',
        'es' => 'Se omitió porque no se cumplió la condición.',
        'fr' => 'Ignorée, car la condition n’était pas remplie.',
        'tr' => 'Koşul sağlanmadığı için atlandı.',
    ];

    /** In-memory flag: answer steps may still run and explain this failure. */
    public const META_REPORTABLE = 'reportable_failure';

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

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failed(string $error, array $metadata = []): self
    {
        return new self(NodeStatus::Failed, error: $error, metadata: $metadata);
    }

    /**
     * The step failed, but the error is something the answering step should
     * explain (a connected system answered with an error). The card stays
     * `failed`; answer-type dependents still run.
     *
     * @param array<string, mixed> $metadata
     */
    public static function reportableFailure(string $error, array $metadata = []): self
    {
        $metadata[self::META_REPORTABLE] = true;

        return new self(NodeStatus::Failed, error: $error, metadata: $metadata);
    }

    public static function skipped(string $reason): self
    {
        return new self(NodeStatus::Skipped, error: $reason);
    }

    /**
     * A step that did not run because a step it needs did not finish, or was
     * stopped by a condition. That step's card says what happened; this one
     * says why it did not run, in the run's language. A step skipped in turn
     * keeps the first reason.
     */
    public static function afterDependency(?self $dependency, mixed $language): self
    {
        if (null !== $dependency && NodeStatus::Skipped === $dependency->status && null !== $dependency->error) {
            return self::skipped($dependency->error);
        }
        $texts = null !== $dependency && NodeStatus::Stopped === $dependency->status
            ? self::SKIPPED_BY_CONDITION
            : self::SKIPPED_AFTER_FAILURE;

        return self::skipped($texts[is_string($language) ? $language : 'en'] ?? $texts['en']);
    }

    /**
     * A condition evaluated false. Dependents skip; the run itself completed.
     */
    public static function stopped(string $reason = 'Condition was not met'): self
    {
        return new self(NodeStatus::Stopped, error: $reason);
    }

    /**
     * Media detached to a background {@see \App\Service\Media\MediaJob} — no file
     * yet; Sprint C completes the card when the job reaches a terminal state.
     *
     * @param array<string, mixed> $metadata
     */
    public static function running(array $metadata = []): self
    {
        return new self(NodeStatus::Running, metadata: $metadata);
    }

    /**
     * Unattended write paused until the owner approves.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $metadata
     */
    public static function waitingApproval(int $approvalId, array $args = [], array $metadata = []): self
    {
        return new self(
            NodeStatus::WaitingApproval,
            metadata: array_merge($metadata, [
                'approval_id' => $approvalId,
                'approved_args' => $args,
            ]),
        );
    }

    public function firstFile(): ?array
    {
        return $this->files[0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return NodeStatus::Done === $this->status;
    }

    public function isReportableFailure(): bool
    {
        return NodeStatus::Failed === $this->status && true === ($this->metadata[self::META_REPORTABLE] ?? false);
    }

    public function isRunning(): bool
    {
        return NodeStatus::Running === $this->status;
    }

    public function isSettledUnsuccessful(): bool
    {
        return NodeStatus::Failed === $this->status
            || NodeStatus::Skipped === $this->status
            || NodeStatus::Stopped === $this->status;
    }

    public function isStopped(): bool
    {
        return NodeStatus::Stopped === $this->status;
    }

    public function isWaitingApproval(): bool
    {
        return NodeStatus::WaitingApproval === $this->status;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The tools a chat run may offer and run, beside the loop's own
 * `update_plan` and `finish`.
 */
interface ChatRunToolbox
{
    /**
     * The person's own connected tools are declared by name only, behind
     * {@see ConnectedToolCatalog}, until the model opens them.
     *
     * @param list<string> $opened connected tools the model opened in this run
     *
     * @return list<array<string, mixed>> function declarations for this unit
     */
    public function declarations(ChatRunScope $scope, array $opened = []): array;

    /**
     * Runs one call. A write that needs the owner's approval comes back as
     * {@see ChatToolResult::waitsFor()}, never as run. With $approvalId the
     * owner approved exactly this call already.
     *
     * @param array{id: string, name: string, args: array<string, mixed>} $call
     */
    public function run(ChatRunScope $scope, ChatRunState $state, array $call, ?int $approvalId = null): ChatToolResult;

    /** A read whose repeat with the same arguments may be answered from the transcript. */
    public function isRead(string $name): bool;
}

<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;

/**
 * `compose_reply` runner — the terminal assembly node. Gathers the reply text
 * and the file attachments referenced from upstream nodes into the node result;
 * the ResultAssembler then surfaces it as the user-facing reply. No model call.
 *
 * Inputs:
 *   - `text`        : string (typically "$nX.text")
 *   - `attachments` : list of file descriptors (typically ["$nX.file", ...])
 */
final readonly class ComposeReplyRunner implements TaskRunner
{
    public function supportedCapabilities(): array
    {
        return [Capability::ComposeReply];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);

        $text = $inputs['text'] ?? '';
        if (is_array($text)) {
            $text = implode("\n\n", array_filter($text, 'is_string'));
        }
        $text = is_string($text) ? $text : '';

        $files = [];
        foreach ($this->flatten($inputs['attachments'] ?? []) as $candidate) {
            if (is_array($candidate) && isset($candidate['path'])) {
                $files[] = $candidate;
            }
        }

        return NodeResult::ok('' === $text ? null : $text, $files);
    }

    /**
     * Flatten one level of nested arrays so both `["$n3.file"]` (each resolving
     * to a descriptor) and a pre-resolved list of descriptors work.
     *
     * @return list<mixed>
     */
    private function flatten(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) && !isset($item['path'])) {
                foreach ($item as $sub) {
                    $out[] = $sub;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }
}

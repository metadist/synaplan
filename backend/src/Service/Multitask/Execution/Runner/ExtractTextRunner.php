<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;

/**
 * `extract_text` runner.
 *
 * Text extraction already happened in MessagePreProcessor (Tika for documents,
 * Whisper for audio) before routing, so this runner is a cheap reader: it pulls
 * the extracted text from the resolved input files (or the message's legacy
 * fileText) and exposes it as the node's text output. No model call.
 */
final readonly class ExtractTextRunner implements TaskRunner
{
    public function supportedCapabilities(): array
    {
        return [Capability::ExtractText];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $inputs = $context->resolveInputs($node);

        $text = '';
        $files = $inputs['files'] ?? null;
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_array($file) && is_string($file['text'] ?? null) && '' !== $file['text']) {
                    $text .= ('' === $text ? '' : "\n\n").$file['text'];
                }
            }
        }

        if ('' === $text) {
            $text = (string) ($context->message->getFileText() ?: '');
        }

        if ('' === $text) {
            return NodeResult::failed('no extractable text found in attachments');
        }

        return NodeResult::ok($text, [], ['source' => 'preprocessed_extraction']);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\InputFileText;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\StepFiles;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;

/**
 * `extract_text` runner.
 *
 * An upload's text was extracted in MessagePreProcessor (Tika for documents,
 * Whisper for audio) before routing. A file an earlier step produced, such as
 * a folder read, is extracted on first use ({@see InputFileText}). The runner
 * exposes that text as the node's text output. No model call.
 */
final readonly class ExtractTextRunner implements TaskRunner
{
    public function __construct(
        private InputFileText $fileText,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::ExtractText];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::ExtractText, 'Extract text from an attached document or audio file (no model choice needed).'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $files = StepFiles::of($node, $context, $context->resolveInputs($node));

        $parts = [];
        $empty = [];
        foreach ($files as $file) {
            $text = $this->fileText->of($file, $context->userId ?? 0);
            $name = $this->nameOf($file);
            if ('' === trim($text)) {
                $empty[] = $name;
                continue;
            }
            // Several named files: the next step needs to know which text is which.
            $parts[] = count($files) > 1 && '' !== $name ? $name.":\n".$text : $text;
        }

        $text = implode("\n\n", $parts);
        if ('' === $text) {
            $text = (string) ($context->message->getFileText() ?: '');
        }

        if ('' === $text) {
            return NodeResult::failed([] !== $empty
                ? 'Synaplan found no text in '.InputFileText::listNames($empty).'.'
                : 'There was no file to read text from.');
        }
        if ([] !== $empty && [] !== $parts) {
            $text .= "\n\nNo text was found in ".InputFileText::listNames($empty).'.';
        }

        return NodeResult::ok($text, [], ['source' => 'preprocessed_extraction']);
    }

    /**
     * @param array<mixed> $file
     */
    private function nameOf(array $file): string
    {
        if (is_string($file['name'] ?? null) && '' !== $file['name']) {
            return $file['name'];
        }
        $path = $file['local_path'] ?? $file['path'] ?? null;

        return is_string($path) ? basename($path) : '';
    }
}

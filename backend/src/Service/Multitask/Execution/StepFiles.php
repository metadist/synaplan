<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskNode;

/**
 * The files a step works on: the file descriptors wired into its inputs,
 * then every file a folder read it depends on produced. A folder read hands
 * over all of its files even when the plan wired only the first (`$n1.file`),
 * so "make one table of the invoices" never quietly covers one invoice.
 */
final class StepFiles
{
    /** Input keys that carry instructions or code, never files. */
    private const NOT_FILES = ['prompt', 'text', 'script'];

    /**
     * @param array<string, mixed> $inputs the step's resolved inputs
     *
     * @return list<array<mixed>>
     */
    public static function of(TaskNode $node, NodeContext $context, array $inputs): array
    {
        $found = [];
        foreach ($inputs as $key => $value) {
            if (!in_array($key, self::NOT_FILES, true)) {
                self::collect($value, $found);
            }
        }
        foreach ($node->dependsOn as $dependency) {
            $result = $context->getResult($dependency);
            if (null !== $result && $result->isSuccessful() && true === ($result->metadata['folder_fetch'] ?? false)) {
                self::collect($result->files, $found);
            }
        }

        $unique = [];
        foreach ($found as $descriptor) {
            $key = is_int($descriptor['file_id'] ?? null) ? 'id:'.$descriptor['file_id'] : 'path:'.self::path($descriptor);
            $unique[$key] ??= $descriptor;
        }

        return array_values($unique);
    }

    /**
     * Ids of the stored files among them, for steps that mount files.
     *
     * @param list<array<mixed>> $descriptors
     *
     * @return list<int>
     */
    public static function storedIds(array $descriptors): array
    {
        $ids = [];
        foreach ($descriptors as $descriptor) {
            if (is_int($descriptor['file_id'] ?? null) && $descriptor['file_id'] > 0) {
                $ids[] = $descriptor['file_id'];
            }
        }

        return $ids;
    }

    /**
     * Arrays carrying a `path` or `local_path`, at any depth.
     *
     * @param list<array<mixed>> $found
     */
    private static function collect(mixed $value, array &$found): void
    {
        if (!is_array($value)) {
            return;
        }
        if ('' !== self::path($value)) {
            $found[] = $value;

            return;
        }
        foreach ($value as $item) {
            self::collect($item, $found);
        }
    }

    /**
     * @param array<mixed> $descriptor
     */
    private static function path(array $descriptor): string
    {
        foreach (['local_path', 'path'] as $key) {
            if (is_string($descriptor[$key] ?? null) && '' !== $descriptor[$key]) {
                return $descriptor[$key];
            }
        }

        return '';
    }
}

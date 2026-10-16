<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Collapses version and edit siblings into the one row the reader should see.
 *
 * The hidden siblings stay available as `versions` / `edits` on the visible
 * row so the app can step between them. A row with no version metadata is
 * left untouched.
 */
final class MessageTurnView
{
    /**
     * @param list<array<string, mixed>> $rows oldest first
     *
     * @return list<array<string, mixed>>
     */
    public static function present(array $rows): array
    {
        $versions = self::groups($rows, 'versionGroup', 'versionIndex', 'versionSelected');
        $edits = self::groups($rows, 'editGroup', 'editIndex', 'editSelected');

        $visible = [];
        foreach ($rows as $row) {
            if (true === ($row['branchInactive'] ?? false)) {
                continue;
            }
            if (false === ($row['versionSelected'] ?? true)) {
                continue;
            }
            if (false === ($row['editSelected'] ?? true)) {
                continue;
            }
            $group = $row['versionGroup'] ?? null;
            if (is_int($group) && isset($versions[$group]) && count($versions[$group]) > 1) {
                $row['versions'] = $versions[$group];
            }
            $editGroup = $row['editGroup'] ?? null;
            if (is_int($editGroup) && isset($edits[$editGroup]) && count($edits[$editGroup]) > 1) {
                $row['edits'] = $edits[$editGroup];
            }
            $visible[] = $row;
        }

        return $visible;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<array{id: int, index: int, selected: bool, model: string|null}>>
     */
    private static function groups(array $rows, string $groupKey, string $indexKey, string $selectedKey): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $group = $row[$groupKey] ?? null;
            $id = $row['id'] ?? null;
            if (!is_int($group) || !is_int($id)) {
                continue;
            }
            $model = null;
            $aiModels = $row['aiModels'] ?? null;
            if (is_array($aiModels) && is_array($aiModels['chat'] ?? null)) {
                $name = $aiModels['chat']['model'] ?? null;
                $model = is_string($name) && '' !== $name ? $name : null;
            }
            $groups[$group][] = [
                'id' => $id,
                'index' => is_int($row[$indexKey] ?? null) ? $row[$indexKey] : 1,
                'selected' => false !== ($row[$selectedKey] ?? true),
                'model' => $model,
            ];
        }
        foreach ($groups as &$members) {
            usort($members, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        }

        return $groups;
    }
}

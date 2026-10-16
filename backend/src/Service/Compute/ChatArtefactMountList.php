<?php

declare(strict_types=1);

namespace App\Service\Compute;

/**
 * Decides which earlier files in this chat a file-work run may mount.
 *
 * Only names the person or the planner pointed at are added. Every earlier
 * file is never mounted: a model-written script must not receive files the
 * person did not name, and a long chat can blow the file cap.
 */
final class ChatArtefactMountList
{
    /**
     * @param list<int>                          $attachedIds    ids already mounted from this turn
     * @param list<array{id: int, name: string}> $chatFiles      newest first
     * @param list<string>                       $requestedNames
     *
     * @return array{ids: list<int>, missing: list<string>}
     */
    public function resolve(array $attachedIds, array $chatFiles, array $requestedNames, string $userText): array
    {
        $ids = [];
        foreach ($attachedIds as $id) {
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $byName = [];
        foreach ($chatFiles as $file) {
            $name = trim($file['name']);
            if ('' === $name || $file['id'] <= 0) {
                continue;
            }
            $key = mb_strtolower($name);
            if (!isset($byName[$key])) {
                $byName[$key] = $file;
            }
        }

        $missing = [];
        $asked = false;
        foreach ($requestedNames as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            $asked = true;
            $hit = $byName[mb_strtolower($name)] ?? null;
            if (null === $hit) {
                $missing[] = $name;
                continue;
            }
            if (!in_array($hit['id'], $ids, true)) {
                $ids[] = $hit['id'];
            }
        }

        $text = mb_strtolower($userText);
        foreach ($byName as $key => $file) {
            if (!str_contains($key, '.') || mb_strlen($key) < 3) {
                continue;
            }
            if (str_contains($text, $key) && !in_array($file['id'], $ids, true)) {
                $ids[] = $file['id'];
                $asked = true;
            }
        }

        if (!$asked && 1 === preg_match('/\b(just made|you just|that file|the spreadsheet|die datei|le fichier|el archivo|bu dosya)\b/iu', $userText)) {
            $prior = [];
            foreach ($chatFiles as $file) {
                if ($file['id'] > 0 && !in_array($file['id'], $attachedIds, true)) {
                    $prior[] = $file;
                }
            }
            if (1 === count($prior)) {
                $ids[] = $prior[0]['id'];
            }
        }

        return ['ids' => $ids, 'missing' => $missing];
    }
}

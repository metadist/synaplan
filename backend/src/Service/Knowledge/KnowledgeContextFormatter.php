<?php

declare(strict_types=1);

namespace App\Service\Knowledge;

/**
 * Shared wording for RAG / memory context blocks injected into prompts.
 *
 * Extracted so ChatHandler, ChatRunner, and the Messages gateway context
 * injector stay byte-compatible for the user-visible rules (especially
 * `[Memory:ID]` citation).
 */
final class KnowledgeContextFormatter
{
    /**
     * @param list<array<string, mixed>> $ragResults
     */
    public function formatRagContext(array $ragResults): string
    {
        if ([] === $ragResults) {
            return '';
        }

        // Deterministic order when ids are present (cache-safe for the gateway).
        $hasIds = true;
        foreach ($ragResults as $row) {
            if (!isset($row['id']) && !isset($row['chunk_id'])) {
                $hasIds = false;
                break;
            }
        }
        if ($hasIds) {
            usort($ragResults, static function (array $a, array $b): int {
                $idA = $a['id'] ?? $a['chunk_id'];
                $idB = $b['id'] ?? $b['chunk_id'];

                return $idA <=> $idB;
            });
        }

        $ragContext = "\n\n## Knowledge Base Context (relevant to your task):\n";
        foreach ($ragResults as $idx => $result) {
            $ragContext .= sprintf(
                "[Source %d] %s\n",
                $idx + 1,
                trim((string) ($result['chunk_text'] ?? '')),
            );
        }
        $ragContext .= "\nUse this context to provide accurate and specific answers.\n";

        return $ragContext;
    }

    /**
     * @param list<array<string, mixed>> $memories
     */
    public function formatMemoriesContext(array $memories): string
    {
        if ([] === $memories) {
            return '';
        }

        usort($memories, static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

        $memoriesContext = "\n\n## User Memories (relevant to this conversation):\n";
        foreach ($memories as $memory) {
            $memoriesContext .= sprintf(
                "[ID: %d] %s: %s\n",
                (int) $memory['id'],
                (string) $memory['key'],
                (string) $memory['value'],
            );
        }
        $memoriesContext .= "\nOnly use memories that are directly relevant to the user's current question. Ignore memories that are not clearly related.\n";
        $memoriesContext .= "REFERENCES: Use [Memory:ID] (clickable). Rules:\n";
        $memoriesContext .= "- ONE ID per bracket. Good: [Memory:42] and [Memory:15]. Bad: [Memory:42, 15].\n";
        $memoriesContext .= "- Only use IDs from the list above. Never invent IDs.\n";

        return $memoriesContext;
    }

    /**
     * Combine RAG + memories and clamp to a hard character budget.
     */
    public function combineAndClamp(string $rag, string $memories, int $maxChars = 8000): string
    {
        $combined = $rag.$memories;
        if ($maxChars <= 0 || mb_strlen($combined) <= $maxChars) {
            return $combined;
        }

        return mb_substr($combined, 0, $maxChars)."\n…";
    }
}

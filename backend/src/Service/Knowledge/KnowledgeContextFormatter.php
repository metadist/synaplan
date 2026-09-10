<?php

declare(strict_types=1);

namespace App\Service\Knowledge;

use App\Service\SelfAware\Docs\PlatformDocsHits;

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
            $label = trim((string) ($result['file_name'] ?? ''));
            $owner = trim((string) ($result['owner_name'] ?? ''));
            if (!empty($result['shared']) && '' !== $owner) {
                $label = '' !== $label ? $label.' ('.$owner.')' : $owner;
            }
            $prefix = '' !== $label ? $label."\n" : '';
            $ragContext .= sprintf(
                "[Source %d] %s%s\n",
                $idx + 1,
                $prefix,
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

    public function formatPlatformDocsContext(PlatformDocsHits $hits): string
    {
        if ($hits->isEmpty()) {
            return '';
        }

        $block = "\n\n## Synaplan documentation (relevant to this question):\n";
        foreach ($hits->hits as $hit) {
            $block .= sprintf(
                "[Doc:%s] %s — %s\n",
                $hit->slug,
                $hit->title,
                trim($hit->text),
            );
        }
        $block .= "\nUse only what is written above for how-to and feature questions. REFERENCES: cite the page you used as [Doc:slug] (clickable). Rules:\n";
        $block .= "- ONE slug per bracket. Good: [Doc:channels] and [Doc:widget]. Bad: [Doc:channels, widget].\n";
        $block .= "- Only slugs from the list above. Never invent slugs or URLs.\n";
        $block .= "- If the documentation does not answer the question, say so and refer the user to the documentation site instead of guessing.\n";

        return $block;
    }

    /**
     * Digest block: references to key messages from OLDER conversations found
     * via the deep-memory index, with optional verbatim excerpts for the top
     * hits. Hard-capped at `$maxChars` — excerpts are dropped (whole, never
     * mid-cut) before digest lines are.
     *
     * @param list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, excerpt: string|null}> $digests
     */
    public function formatDigestContext(array $digests, int $maxChars = 4000): string
    {
        if ([] === $digests) {
            return '';
        }

        $header = "\n\n## Older conversations (references to past messages):\n";
        $footer = "\nThese are the user's own past messages, found by relevance. Use them when the current question refers to something from an earlier conversation.\n";
        $footer .= "REFERENCES: cite as [Message:ID] (clickable). Rules:\n";
        $footer .= "- ONE ID per bracket. Good: [Message:1234]. Bad: [Message:1234, 1235].\n";
        $footer .= "- Only use IDs from the list above. Never invent IDs.\n";

        return $this->formatMessageReferenceBlock($digests, $header, $footer, $maxChars);
    }

    /**
     * Immediate tail of another chat — same [Message:ID] citation rules as digests.
     *
     * @param list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, excerpt: string|null}> $messages
     */
    public function formatOtherChatTail(array $messages, int $maxChars = 4000): string
    {
        if ([] === $messages) {
            return '';
        }

        $header = "\n\n## Recent messages from another conversation:\n";
        $footer = "\nThese are the user's most recent messages from another chat. Use them when the current question continues that conversation.\n";
        $footer .= "REFERENCES: cite as [Message:ID] (clickable). Rules:\n";
        $footer .= "- ONE ID per bracket. Good: [Message:1234]. Bad: [Message:1234, 1235].\n";
        $footer .= "- Only use IDs from the list above. Never invent IDs.\n";

        return $this->formatMessageReferenceBlock($messages, $header, $footer, $maxChars);
    }

    /**
     * @param list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, excerpt: string|null}> $items
     */
    private function formatMessageReferenceBlock(array $items, string $header, string $footer, int $maxChars): string
    {
        $budget = $maxChars - mb_strlen($header) - mb_strlen($footer);

        $lines = [];
        foreach ($items as $item) {
            $line = sprintf(
                "[Msg: %d | %s | %s] %s\n",
                $item['message_id'],
                $item['source_date'] > 0 ? gmdate('Y-m-d', $item['source_date']) : 'unknown date',
                '' !== $item['channel'] ? $item['channel'] : 'chat',
                $item['title'],
            );

            if (mb_strlen($line) > $budget) {
                break;
            }
            $budget -= mb_strlen($line);
            $lines[$item['message_id']] = $line;
        }

        if ([] === $lines) {
            return '';
        }

        foreach ($items as $item) {
            $excerpt = $item['excerpt'] ?? null;
            if (null === $excerpt || '' === $excerpt || !isset($lines[$item['message_id']])) {
                continue;
            }

            $quoted = '> '.str_replace("\n", "\n> ", trim($excerpt))."\n";
            if (mb_strlen($quoted) > $budget) {
                continue;
            }
            $budget -= mb_strlen($quoted);
            $lines[$item['message_id']] .= $quoted;
        }

        return $header.implode('', $lines).$footer;
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

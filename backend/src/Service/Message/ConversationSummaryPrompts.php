<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;

/**
 * The production prompts and input rendering for the rolling conversation
 * summary, extracted so the live-model eval (`app:summary:eval`) exercises
 * EXACTLY what {@see ConversationSummaryService} sends — the eval can never
 * drift from production.
 *
 * Pure string building only: no I/O, no state, no model calls.
 */
final class ConversationSummaryPrompts
{
    private function __construct()
    {
    }

    public static function bootstrapSystemPrompt(int $summaryMaxChars): string
    {
        return <<<PROMPT
            You compress the earlier part of an ongoing chat conversation into a compact "rolling summary" that a chat assistant will read as background context. The most recent turns are shown to the assistant separately and verbatim, so DO NOT restate them — summarize only what is provided below.

            Rules:
            - Write in the SAME language the conversation uses.
            - Apply GRADIENT compression: segments are ordered oldest → newest. Condense the OLDEST segment the hardest (only durable facts / the overall topic). Condense LATER segments progressively less, keeping more specifics for the newest segment.
            - Be factual. Never invent information that is not present in the source.
            - Output plain prose / short bullet lines under these headings (skip a heading when empty):
              ## Topic
              ## User position / goal
              ## Decisions & constraints
              ## Open questions
              ## Already covered / answered
              ## External results
            - "Already covered / answered" is what stops the assistant from repeating earlier answers — list conclusions and deliverables already produced.
            - No preamble, no meta commentary.
            - Keep the whole summary under {$summaryMaxChars} characters.
            PROMPT;
    }

    public static function incrementalSystemPrompt(int $summaryMaxChars): string
    {
        return <<<PROMPT
            You maintain a rolling summary of an ongoing chat. You receive the PREVIOUS rolling summary plus a small set of NEWLY AGED-OUT messages that just left the verbatim window. Fold the new messages into the previous summary and return the updated summary.

            Rules:
            - Write in the SAME language the conversation uses.
            - Keep durable facts from the previous summary; do not drop the user's position, decisions, or open questions unless the new messages explicitly resolve or replace them.
            - Condense older material more aggressively than the newly aged-out messages.
            - Be factual. Never invent information.
            - Output plain prose / short bullet lines under these headings (skip a heading when empty):
              ## Topic
              ## User position / goal
              ## Decisions & constraints
              ## Open questions
              ## Already covered / answered
              ## External results
            - "Already covered / answered" must grow when the new messages contain conclusions or deliverables, so the assistant does not repeat itself.
            - No preamble, no meta commentary.
            - Keep the whole summary under {$summaryMaxChars} characters.
            PROMPT;
    }

    /**
     * Render the older span as recency-tiered segments with per-tier
     * compression hints (the bootstrap user content).
     *
     * @param list<Message> $older chronological (oldest first)
     */
    public static function bootstrapUserContent(array $older, int $tiers): string
    {
        $tiers = max(1, min($tiers, count($older)));
        $perTier = (int) ceil(count($older) / $tiers);
        $chunks = array_chunk($older, max(1, $perTier));
        $segmentCount = count($chunks);

        $lines = [];
        foreach ($chunks as $index => $chunk) {
            $lines[] = sprintf('## Segment %d of %d (%s):', $index + 1, $segmentCount, self::compressionHint($index, $segmentCount));
            foreach ($chunk as $msg) {
                $lines[] = self::renderMessage($msg);
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param list<Message> $newMessages
     */
    public static function incrementalUserContent(string $previousSummary, array $newMessages): string
    {
        $lines = ["## Previous rolling summary\n", $previousSummary, "\n## Newly aged-out messages\n"];
        foreach ($newMessages as $msg) {
            $lines[] = self::renderMessage($msg);
        }

        return trim(implode("\n", $lines));
    }

    public static function renderMessage(Message $msg): string
    {
        $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
        $text = (string) $msg->getText();

        $fileText = (string) $msg->getFileText();
        if ('' !== $fileText) {
            $text .= ' [attached '.((string) $msg->getFileType()).': '.self::clip($fileText, 500).']';
        }

        return sprintf('[#%d %s]: %s', (int) $msg->getId(), $role, self::clip($text, ConversationSummaryConstants::SOURCE_MESSAGE_CHAR_CAP));
    }

    /**
     * Response token budget for a target character cap (reasoning headroom
     * included — the shipped SORT/SUMMARIZE defaults think before answering).
     */
    public static function tokenBudget(int $summaryMaxChars): int
    {
        return max(256, (int) ceil($summaryMaxChars / 3) + 256);
    }

    private static function compressionHint(int $index, int $segmentCount): string
    {
        if ($segmentCount <= 1) {
            return 'condense to the essentials';
        }

        if (0 === $index) {
            return 'oldest — condense aggressively, essentials only';
        }

        if ($index === $segmentCount - 1) {
            return 'most recent of the older turns — condense lightly, keep specifics and the current position';
        }

        return 'middle — condense moderately';
    }

    private static function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxChars)).'…';
    }
}

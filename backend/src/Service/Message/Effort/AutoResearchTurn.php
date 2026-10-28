<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

use App\Entity\Message;
use App\Service\Message\Processing\TurnStatus;

/**
 * The processor's one call into automatic research. Decides, tells the
 * stream, and hands back either the notice that replaces the answer or
 * the limit sentence the answer has to begin with.
 */
final readonly class AutoResearchTurn
{
    /** The status event the chat stream forwards when a research started. */
    public const EVENT_STARTED = 'research_started';

    /** The classification key that carries the limit reason to the caller. */
    public const NOTICE_KEY = 'effort_notice';

    /** The metadata a notice reply carries instead of a model's. */
    public const NOTICE_METADATA = ['provider' => 'research', 'model' => 'research', 'research_notice' => true];

    public function __construct(
        private AutoResearchGate $gate,
        private AutoResearchText $texts,
    ) {
    }

    /**
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param array<int, Message|array{role: string, content: string}> $history
     *
     * @return array{0: array<string, mixed>, 1: string|null, 2: string|null} the classification, the notice reply when a run started, the sentence an answer begins with when a limit held it back
     */
    public function apply(Message $message, array $classification, array $options, array $history, TurnStatus $status): array
    {
        $decision = $this->gate->decide($message, $classification, $options, $history);
        $locale = self::locale($classification);
        if ($decision->startedRun()) {
            $status->report(self::EVENT_STARTED, 'Looking into this in several sources', [
                'run_id' => $decision->runId,
                'minutes' => $decision->minutes,
                'why' => $decision->why,
            ]);
            $classification['research_run_id'] = $decision->runId;

            return [$classification, $this->texts->notice($decision->minutes, (string) $decision->why, $locale), null];
        }
        $reason = $decision->heldBackBy();
        if (null === $reason) {
            return [$classification, null, null];
        }
        $classification[self::NOTICE_KEY] = $reason;

        return [$classification, null, $this->texts->limit($reason, $locale)];
    }

    /**
     * The reply that stands in for the answer when a research started.
     *
     * @return array{content: string, metadata: array<string, mixed>}
     */
    public static function noticeReply(string $notice): array
    {
        return ['content' => $notice, 'metadata' => self::NOTICE_METADATA];
    }

    /**
     * @param array<string, mixed> $classification
     */
    private static function locale(array $classification): string
    {
        $language = $classification['language'] ?? null;

        return is_string($language) && '' !== $language ? strtolower(substr($language, 0, 2)) : 'en';
    }
}

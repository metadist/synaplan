<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Message;

/**
 * Points async media jobs at the reply the person sees.
 *
 * A media step creates its job with the INCOMING message id — the reply
 * does not exist yet. Once the reply is stored, the job is rebound to it, so
 * the worker syncs the finished media onto the assistant bubble instead of
 * the person's own prompt. A fast render that already finished while bound
 * to the incoming message gets the terminal sync it skipped (#1218, #1239).
 */
final readonly class MediaJobRebinder
{
    public function __construct(
        private MediaJobService $mediaJobService,
        private MediaJobMessageSync $mediaJobMessageSync,
    ) {
    }

    public function rebind(string $jobKey, int $outgoingMessageId): void
    {
        $rebound = $this->mediaJobService->rebindMessage($jobKey, $outgoingMessageId);
        if (null !== $rebound && $rebound->isTerminal()) {
            $this->mediaJobMessageSync->syncTerminalState($rebound);
        }
    }

    /**
     * Every task card's job of a multi-task turn. These jobs never surface
     * on the top-level `media_job`, so without this the finished media would
     * replace the person's prompt after a reload.
     *
     * @param array<string, mixed> $metadata handler-result metadata
     */
    public function rebindTaskCards(Message $outgoing, array $metadata): void
    {
        $outgoingId = $outgoing->getId();
        $cards = $metadata['task_plan_render']['cards'] ?? null;
        if (null === $outgoingId || !is_array($cards)) {
            return;
        }

        foreach ($cards as $card) {
            $cardJobKey = is_array($card) ? ($card['job_id'] ?? null) : null;
            if (is_string($cardJobKey) && '' !== $cardJobKey) {
                $this->rebind($cardJobKey, $outgoingId);
            }
        }
    }
}

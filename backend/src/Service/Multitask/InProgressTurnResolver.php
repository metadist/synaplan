<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Entity\Message;

/**
 * Resolves the in-progress task cards for a chat whose newest message is a
 * still-running user turn (#1142).
 *
 * When a user sends a multi-task prompt and then reloads (or returns to the
 * chat) BEFORE the turn finishes, the assistant OUT message row does not exist
 * yet — the chat history endpoint would show only the bare user prompt. This
 * resolver reads the per-node plan rows the DAG executor persists during
 * execution (BMESSAGE_TASKS, keyed on the incoming message id) so the client
 * can render running/completed task cards until the turn completes.
 *
 * Read-only and best-effort: any absence of data yields null and the caller
 * simply returns the persisted messages unchanged.
 */
final readonly class InProgressTurnResolver
{
    public function __construct(
        private TaskPlanStore $taskPlanStore,
    ) {
    }

    /**
     * Build the in-progress turn payload from the chat's newest message, or
     * null when there is no running turn to surface.
     *
     * A running turn is a newest message that is still an inbound (user)
     * message with status 'processing' — the OUT row is only written when the
     * turn completes, so an inbound-and-processing tail means the assistant is
     * still working.
     *
     * @return array{reply_node: string, cards: list<array{nodeId: string, capability: string, kind: string, state: string, text?: string, url?: string, error?: string, query?: string, resultsCount?: int, type?: string}>}|null
     */
    public function resolve(?Message $newestMessage): ?array
    {
        if (null === $newestMessage) {
            return null;
        }
        if ('IN' !== $newestMessage->getDirection() || 'processing' !== $newestMessage->getStatus()) {
            return null;
        }

        $messageId = $newestMessage->getId();
        if (null === $messageId) {
            return null;
        }

        $cards = $this->taskPlanStore->loadCards($messageId);
        if ([] === $cards) {
            return null;
        }

        return [
            'reply_node' => $this->pickReplyNode($cards),
            'cards' => $cards,
        ];
    }

    /**
     * Choose the card whose text becomes the reply body: the first text card,
     * falling back to the first card. Empty string when there are no cards
     * (unreachable — the caller guards on a non-empty list).
     *
     * @param list<array{nodeId: string, capability: string, kind: string, state: string, text?: string, url?: string, error?: string, query?: string, resultsCount?: int, type?: string}> $cards
     */
    private function pickReplyNode(array $cards): string
    {
        foreach ($cards as $card) {
            if ('text' === $card['kind']) {
                return $card['nodeId'];
            }
        }

        return $cards[0]['nodeId'] ?? '';
    }
}

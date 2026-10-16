<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\Chat;
use App\Message\ExtractMemoriesCommand;
use App\Service\Chat\ChatTitleService;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What starts once a reply is stored: the memory job, the rolling summary,
 * the WhatsApp forward and the chat title.
 *
 * The memory job waits for the stored reply (#881). Dispatched earlier, a
 * fast worker found no reply row, wrote the extracted memories onto the
 * incoming message, and the client — polling the reply — never showed the
 * memory toast. The summary refresh likewise needs the finished turn in the
 * history window. Neither blocks the `complete` event.
 */
final readonly class StreamTurnFinisher
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemoryExtractionDispatcher $memoryExtractionDispatcher,
        private ConversationSummaryRefreshDispatcher $conversationSummaryRefreshDispatcher,
        private MessageForwardingService $messageForwardingService,
        private ChatTitleService $chatTitleService,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata handler-result metadata, carrying the deferred `extraction_payload`
     */
    public function afterReply(StreamTurnRequest $turn, ?Chat $chat, array $metadata, string $replyText): void
    {
        if (!$turn->incognito) {
            $chat?->updateTimestamp();
            $this->em->flush();

            // Incognito never extracts: ChatHandler returns no payload for
            // it, and this branch is the second line of defence.
            $payload = $metadata['extraction_payload'] ?? null;
            if ($payload instanceof ExtractMemoriesCommand) {
                $this->memoryExtractionDispatcher->dispatch($payload);
            }

            if (null !== $chat) {
                $this->conversationSummaryRefreshDispatcher->dispatch(
                    (int) $chat->getId(),
                    (int) $turn->caller->user->getId(),
                );
            }
        }

        if ($this->isWebChat($turn) && $chat) {
            $this->messageForwardingService->forwardIfNeeded($chat, $replyText);
        }
    }

    /**
     * Names the conversation once its first exchange is stored, so the
     * sidebar stops filling with "Hi" and "New Chat" (#1500). At most once
     * per chat; a title the person typed is kept. Widget sessions title
     * themselves, and incognito stores nothing to name.
     */
    public function titleChat(StreamTurnRequest $turn, ?Chat $chat): ?string
    {
        if (!$this->isWebChat($turn) || !$chat) {
            return null;
        }

        return $this->chatTitleService->titleWebChatIfNeeded($chat, (int) $turn->caller->user->getId());
    }

    private function isWebChat(StreamTurnRequest $turn): bool
    {
        return !$turn->caller->isWidget() && !$turn->caller->isGuest() && !$turn->incognito;
    }
}

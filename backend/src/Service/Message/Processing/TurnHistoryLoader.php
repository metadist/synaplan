<?php

namespace App\Service\Message\Processing;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\ConversationSummaryService;
use App\Service\Message\MessageVersionService;
use Psr\Log\LoggerInterface;

/**
 * The conversation a turn is answered in: the request-supplied history for
 * incognito, the active turns of the chat, or — for legacy messages without
 * a chat — the tracking-id thread. Older turns of a long chat come back as a
 * rolling summary.
 */
final readonly class TurnHistoryLoader
{
    /**
     * Conversation window replayed verbatim to the models on every turn.
     *
     * Every message in this window is re-sent on each turn, to the sorter AND
     * the answer model, so the window is paid for in prompt tokens and in
     * provider time-to-first-token. Older turns are not lost: once a chat grows
     * past the window, {@see ConversationSummaryService} condenses the earlier
     * span into the system prompt.
     */
    public const HISTORY_MAX_MESSAGES = 15;

    public const HISTORY_MAX_CHARS = 15000;

    public function __construct(
        private MessageRepository $messageRepository,
        private ConversationSummaryService $conversationSummaryService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int, Message> oldest first
     */
    public function load(Message $message, array $options): array
    {
        if (!empty($options['incognito'])) {
            // Nothing is persisted, so the client ships the history in the
            // request (already capped by the stream request resolver).
            $history = $this->incognitoHistory($message, $options['incognito_history'] ?? []);
            $this->logger->debug('Using request-supplied incognito history', ['history_count' => count($history)]);

            return $history;
        }

        if ($message->getChatId()) {
            $history = $this->activeChatHistory($message);
            $this->logger->debug('Using chat history', ['chat_id' => $message->getChatId(), 'history_count' => count($history)]);

            return $history;
        }

        $history = $this->messageRepository->findConversationHistory($message->getUserId(), (string) $message->getTrackingId(), 10, $message->getId());
        $this->logger->debug('Using legacy trackingId history', ['tracking_id' => $message->getTrackingId(), 'history_count' => count($history)]);

        return $history;
    }

    /**
     * The stored summary of older turns for the system prompt, while the
     * newest turns stay verbatim. Never calls a model on the hot path — the
     * worker refreshes the store after the turn is persisted
     * ({@see \App\Message\RefreshConversationSummaryCommand}), so this is a
     * read that must not show up in time-to-first-token. Chat-style turns in
     * a persisted chat only.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     * @param array<int, Message>  $history
     *
     * @return array{0: array<string, mixed>, 1: array<int, Message>} updated [$options, $history]
     */
    public function applyRollingSummary(Message $message, array $classification, array $options, array $history, ?PerfTimer $perfTimer = null): array
    {
        $chatId = $message->getChatId();
        if ('chat' !== ($classification['intent'] ?? 'chat') || null === $chatId || $chatId <= 0) {
            return [$options, $history];
        }

        $perfTimer?->start('summary');
        $rolling = $this->conversationSummaryService->buildRollingContext(
            $history,
            $this->messageRepository->countByChatId($chatId, excludeFailed: false),
            $message->getUserId(),
            $chatId,
        );
        if ($rolling->applied) {
            $options['conversation_summary'] = $rolling->summary;
            $history = $rolling->recentMessages;
        }
        $perfTimer?->stop('summary');

        return [$options, $history];
    }

    /**
     * Transient Message entities from the request-supplied history, so the
     * classifier and handlers see the shape of a loaded chat. Never persisted;
     * their ids stay null.
     *
     * @param array<int, array{role: string, content: string}> $history oldest first
     *
     * @return list<Message>
     */
    private function incognitoHistory(Message $currentMessage, array $history): array
    {
        $entities = [];
        $baseTimestamp = time() - count($history) - 1;

        foreach (array_values($history) as $index => $entry) {
            if ('' === $entry['content']) {
                continue;
            }

            $historyMessage = new Message();
            $historyMessage->setUserId($currentMessage->getUserId());
            $historyMessage->setTrackingId($currentMessage->getTrackingId());
            $historyMessage->setProviderIndex('WEB');
            // Monotonic timestamps keep the thread order stable for consumers
            // that sort by time.
            $historyMessage->setUnixTimestamp($baseTimestamp + $index);
            $historyMessage->setDateTime(date('YmdHis', $baseTimestamp + $index));
            $historyMessage->setMessageType('WEB');
            $historyMessage->setFile(0);
            $historyMessage->setTopic('CHAT');
            $historyMessage->setLanguage($currentMessage->getLanguage() ?: 'en');
            $historyMessage->setText($entry['content']);
            $historyMessage->setDirection('assistant' === $entry['role'] ? 'OUT' : 'IN');
            $historyMessage->setStatus('complete');

            $entities[] = $historyMessage;
        }

        return $entities;
    }

    /**
     * Active turns only, then the message and character caps. Unselected
     * versions are dropped before the cap so a long "Again" trail cannot push
     * the real conversation out of the window.
     *
     * @return list<Message>
     */
    private function activeChatHistory(Message $message): array
    {
        $chatId = $message->getChatId();
        if (null === $chatId) {
            return [];
        }
        $fetched = $this->messageRepository->findChatHistory(
            $message->getUserId(),
            $chatId,
            self::HISTORY_MAX_MESSAGES * 8,
            self::HISTORY_MAX_CHARS * 8,
            $message->getId(),
        );
        $active = (new MessageVersionService())->activeForContext($fetched);
        $newestFirst = [];
        $chars = 0;
        foreach (array_reverse($active) as $row) {
            $length = strlen($row->getText());
            if ([] !== $newestFirst && ($chars + $length) > self::HISTORY_MAX_CHARS) {
                break;
            }
            if (count($newestFirst) >= self::HISTORY_MAX_MESSAGES) {
                break;
            }
            $newestFirst[] = $row;
            $chars += $length;
        }

        return array_reverse($newestFirst);
    }
}

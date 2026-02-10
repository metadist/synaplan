<?php

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\WidgetSession;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Widget Session Management Service.
 *
 * Handles anonymous user sessions for chat widgets
 */
class WidgetSessionService
{
    // Session limits (from BCONFIG table, but with defaults)
    public const DEFAULT_MAX_MESSAGES = 50;         // Total messages per session
    public const DEFAULT_MAX_PER_MINUTE = 10;       // Messages per minute
    public const DEFAULT_MAX_FILES = 3;             // File uploads per session
    public const SESSION_EXPIRY_HOURS = 24;         // Session expires after 24h of inactivity

    private int $maxMessages = self::DEFAULT_MAX_MESSAGES;
    private int $maxPerMinute = self::DEFAULT_MAX_PER_MINUTE;
    private int $maxFiles = self::DEFAULT_MAX_FILES;

    public function __construct(
        private EntityManagerInterface $em,
        private WidgetSessionRepository $sessionRepository,
        private MessageRepository $messageRepository,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get or create a session for a widget.
     *
     * @param string $widgetId            The widget ID
     * @param string $sessionId           The session ID (will be prefixed with 'test_' if isValidatedTestMode is true)
     * @param bool   $isValidatedTestMode Whether test mode was validated server-side (owner authenticated)
     */
    public function getOrCreateSession(string $widgetId, string $sessionId, bool $isValidatedTestMode = false): WidgetSession
    {
        // If validated test mode, ensure session ID has test_ prefix
        // This is the ONLY place where test_ prefix can be added (server-side validated)
        $effectiveSessionId = $sessionId;
        if ($isValidatedTestMode && !str_starts_with($sessionId, 'test_')) {
            $effectiveSessionId = 'test_'.$sessionId;
        }

        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $effectiveSessionId);

        if (!$session) {
            $session = new WidgetSession();
            $session->setWidgetId($widgetId);
            $session->setSessionId($effectiveSessionId);
            $this->em->persist($session);
            $this->em->flush();

            $this->logger->info('New widget session created', [
                'widget_id' => $widgetId,
                'session_id' => substr($effectiveSessionId, 0, 12).'...',
                'is_test' => $session->isTest(),
            ]);
        } elseif ($session->isExpired()) {
            // Reset expired session
            $session->setMessageCount(0);
            $session->setFileCount(0);
            $session->setExpires(time() + (self::SESSION_EXPIRY_HOURS * 3600));
            $this->em->flush();

            $this->logger->info('Widget session reset after expiry', [
                'widget_id' => $widgetId,
                'session_id' => substr($effectiveSessionId, 0, 12).'...',
            ]);
        }

        return $session;
    }

    /**
     * Check if session can send a message (rate limits).
     */
    public function checkSessionLimit(WidgetSession $session, ?int $maxMessages = null, ?int $maxPerMinute = null): array
    {
        $maxMessages = $maxMessages ?? $this->maxMessages;
        $maxPerMinute = $maxPerMinute ?? $this->maxPerMinute;

        // Check total message limit
        if ($session->getMessageCount() >= $maxMessages) {
            return [
                'allowed' => false,
                'reason' => 'total_limit_reached',
                'remaining' => 0,
                'retry_after' => null,
                'max_messages' => $maxMessages,
            ];
        }

        // Check per-minute limit
        if ($maxPerMinute > 0) {
            $lastMinute = time() - 60;
            if ($session->getLastMessage() >= $lastMinute) {
                $messagesInLastMinute = $this->getMessagesInLastMinute($session);

                if ($messagesInLastMinute >= $maxPerMinute) {
                    $retryAfter = 60 - (time() - $session->getLastMessage());

                    return [
                        'allowed' => false,
                        'reason' => 'rate_limit_exceeded',
                        'remaining' => 0,
                        'retry_after' => $retryAfter,
                        'max_per_minute' => $maxPerMinute,
                    ];
                }
            }
        }

        $remaining = max(0, $maxMessages - $session->getMessageCount());

        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => $remaining,
            'retry_after' => null,
            'max_messages' => $maxMessages,
        ];
    }

    /**
     * Increment message count and update last message time.
     */
    public function incrementMessageCount(WidgetSession $session): void
    {
        $session->incrementMessageCount();
        $session->updateLastMessage();
        $this->em->flush();
    }

    /**
     * Generate an AI summary title for the session after 5 user messages.
     * This should be called asynchronously to not block the response.
     * Note: Only generates if no title exists (preserves manually set titles).
     */
    public function generateTitleIfNeeded(WidgetSession $session, int $ownerId): void
    {
        // Skip if title already exists (includes manually set titles)
        if (null !== $session->getTitle()) {
            $this->logger->debug('Skipping title generation - title already exists', [
                'session_id' => $session->getSessionId(),
                'title' => $session->getTitle(),
            ]);

            return;
        }

        $chatId = $session->getChatId();
        if (!$chatId) {
            $this->logger->debug('Skipping title generation - no chatId');

            return;
        }

        // Count actual user messages from DB (not messageCount, which isn't updated in human mode)
        $messages = $this->messageRepository->findChatHistory($ownerId, $chatId, 20, 50000);
        $userMessages = array_filter($messages, fn ($m) => 'IN' === $m->getDirection());
        $userMessageCount = count($userMessages);

        $this->logger->info('Title generation check', [
            'session_id' => $session->getSessionId(),
            'user_message_count' => $userMessageCount,
            'has_title' => false,
        ]);

        // Only generate title if >= 5 user messages
        if ($userMessageCount < 5) {
            $this->logger->debug('Skipping title generation - not enough user messages', [
                'count' => $userMessageCount,
            ]);

            return;
        }

        $this->logger->info('Proceeding with title generation', [
            'session_id' => $session->getSessionId(),
            'user_message_count' => $userMessageCount,
        ]);

        try {
            // Build conversation text for summarization (using already fetched user messages)
            $conversationText = '';
            foreach ($userMessages as $message) {
                $text = mb_substr($message->getText(), 0, 200);
                $conversationText .= "- {$text}\n";
            }

            // Use gpt-4o-mini for cheap, fast summarization
            $aiOptions = ['temperature' => 0.3];
            $provider = $this->modelConfigService->getProviderForModel(73);
            $modelName = $this->modelConfigService->getModelName(73);
            if ($provider && $modelName) {
                $aiOptions['provider'] = $provider;
                $aiOptions['model'] = $modelName;
            }

            $prompt = <<<PROMPT
Based on these user questions/messages, create a short title (3-5 words) that describes what the user is asking about.
Only output the title, nothing else. No quotes, no punctuation at the end.

User messages:
{$conversationText}

Title:
PROMPT;

            $response = $this->aiFacade->chat(
                [['role' => 'user', 'content' => $prompt]],
                $ownerId,
                $aiOptions
            );

            $title = trim($response['content'] ?? '');
            // Clean up: remove quotes and limit length
            $title = trim($title, '"\'');
            $title = mb_substr($title, 0, 50);

            if (!empty($title)) {
                // Re-fetch session to check for race condition (another request may have set title)
                $this->em->refresh($session);
                if (null !== $session->getTitle()) {
                    $this->logger->debug('Title already set by another process, discarding generated title', [
                        'session_id' => substr($session->getSessionId(), 0, 12).'...',
                        'existing_title' => $session->getTitle(),
                        'discarded_title' => $title,
                    ]);

                    return;
                }

                $session->setTitle($title);
                $this->em->flush();

                $this->logger->info('Generated AI title for widget session', [
                    'session_id' => substr($session->getSessionId(), 0, 12).'...',
                    'title' => $title,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to generate AI title for session', [
                'session_id' => substr($session->getSessionId(), 0, 12).'...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decrement message count (e.g. on failure).
     */
    public function decrementMessageCount(WidgetSession $session): void
    {
        $session->setMessageCount(max(0, $session->getMessageCount() - 1));
        $this->em->flush();
    }

    public function checkFileUploadLimit(WidgetSession $session, ?int $maxFiles = null): array
    {
        $maxFiles = $maxFiles ?? $this->maxFiles;

        if ($maxFiles <= 0) {
            return [
                'allowed' => true,
                'reason' => null,
                'remaining' => null,
                'max_files' => $maxFiles,
            ];
        }

        if ($session->getFileCount() >= $maxFiles) {
            return [
                'allowed' => false,
                'reason' => 'file_limit_reached',
                'remaining' => 0,
                'max_files' => $maxFiles,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => max(0, $maxFiles - $session->getFileCount()),
            'max_files' => $maxFiles,
        ];
    }

    public function incrementFileCount(WidgetSession $session): void
    {
        $session->incrementFileCount();
        $this->em->flush();
    }

    /**
     * Fetch an existing session without modifying it.
     */
    public function getSession(string $widgetId, string $sessionId): ?WidgetSession
    {
        return $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);
    }

    /**
     * Attach a chat to the session if not already linked.
     */
    public function attachChat(WidgetSession $session, Chat $chat): void
    {
        if ($session->getChatId() !== $chat->getId()) {
            $session->setChatId($chat->getId());
            $this->em->flush();
        }
    }

    /**
     * Map chat IDs to widget session metadata.
     *
     * @param array<int> $chatIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSessionMapForChats(array $chatIds): array
    {
        $rows = $this->sessionRepository->findSessionsByChatIds($chatIds);

        $map = [];
        foreach ($rows as $row) {
            $chatId = (int) $row['chat_id'];
            $map[$chatId] = [
                'widgetId' => $row['widget_id'],
                'widgetName' => $row['widget_name'] ?? null,
                'sessionId' => $row['session_id'],
                'messageCount' => (int) $row['message_count'],
                'fileCount' => isset($row['file_count']) ? (int) $row['file_count'] : 0,
                'lastMessage' => null !== $row['last_message'] ? (int) $row['last_message'] : null,
                'created' => (int) $row['created'],
                'expires' => (int) $row['expires'],
            ];
        }

        return $map;
    }

    /**
     * Get messages sent in the last minute.
     */
    private function getMessagesInLastMinute(WidgetSession $session): int
    {
        // Simplified: assume 1 message if last message was within the last minute
        // In production, track per-second timestamps in cache
        $lastMinute = time() - 60;

        return $session->getLastMessage() >= $lastMinute ? 1 : 0;
    }

    /**
     * Cleanup expired sessions (run via cron).
     */
    public function cleanupExpiredSessions(): int
    {
        $deleted = $this->sessionRepository->deleteExpiredSessions();

        $this->logger->info('Cleaned up expired widget sessions', [
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Get session statistics for a widget.
     */
    public function getWidgetStats(string $widgetId): array
    {
        return [
            'active_sessions' => $this->sessionRepository->countActiveSessionsByWidget($widgetId),
            'total_messages' => $this->sessionRepository->getTotalMessageCountByWidget($widgetId),
        ];
    }
}

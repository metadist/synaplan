<?php

namespace App\Service;

use App\Entity\WidgetSession;
use App\Repository\WidgetSessionRepository;
use App\Entity\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Widget Session Management Service
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
        private LoggerInterface $logger
    ) {}

    /**
     * Get or create a session for a widget
     */
    public function getOrCreateSession(string $widgetId, string $sessionId): WidgetSession
    {
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            $session = new WidgetSession();
            $session->setWidgetId($widgetId);
            $session->setSessionId($sessionId);
            $this->em->persist($session);
            $this->em->flush();

            $this->logger->info('New widget session created', [
                'widget_id' => $widgetId,
                'session_id' => substr($sessionId, 0, 8) . '...'
            ]);
        } elseif ($session->isExpired()) {
            // Reset expired session
            $session->setMessageCount(0);
            $session->setFileCount(0);
            $session->setExpires(time() + (self::SESSION_EXPIRY_HOURS * 3600));
            $this->em->flush();

            $this->logger->info('Widget session reset after expiry', [
                'widget_id' => $widgetId,
                'session_id' => substr($sessionId, 0, 8) . '...'
            ]);
        }

        return $session;
    }

    /**
     * Check if session can send a message (rate limits)
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
                'max_messages' => $maxMessages
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
                        'max_per_minute' => $maxPerMinute
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
            'max_messages' => $maxMessages
        ];
    }

    /**
     * Increment message count and update last message time
     */
    public function incrementMessageCount(WidgetSession $session): void
    {
        $session->incrementMessageCount();
        $session->updateLastMessage();
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
                'max_files' => $maxFiles
            ];
        }

        if ($session->getFileCount() >= $maxFiles) {
            return [
                'allowed' => false,
                'reason' => 'file_limit_reached',
                'remaining' => 0,
                'max_files' => $maxFiles
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => max(0, $maxFiles - $session->getFileCount()),
            'max_files' => $maxFiles
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
                'lastMessage' => $row['last_message'] !== null ? (int) $row['last_message'] : null,
                'created' => (int) $row['created'],
                'expires' => (int) $row['expires']
            ];
        }

        return $map;
    }

    /**
     * Get messages sent in the last minute
     * (For now, we'll implement a simple check; in production, use Redis/Cache)
     */
    private function getMessagesInLastMinute(WidgetSession $session): int
    {
        // Simplified: assume 1 message if last message was within the last minute
        // In production, track per-second timestamps in cache
        $lastMinute = time() - 60;
        return $session->getLastMessage() >= $lastMinute ? 1 : 0;
    }

    /**
     * Cleanup expired sessions (run via cron)
     */
    public function cleanupExpiredSessions(): int
    {
        $deleted = $this->sessionRepository->deleteExpiredSessions();
        
        $this->logger->info('Cleaned up expired widget sessions', [
            'deleted_count' => $deleted
        ]);

        return $deleted;
    }

    /**
     * Get session statistics for a widget
     */
    public function getWidgetStats(string $widgetId): array
    {
        return [
            'active_sessions' => $this->sessionRepository->countActiveSessionsByWidget($widgetId),
            'total_messages' => $this->sessionRepository->getTotalMessageCountByWidget($widgetId)
        ];
    }
}


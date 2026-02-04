<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Widget;
use App\Entity\WidgetSummary;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\WidgetSessionRepository;
use App\Repository\WidgetSummaryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating summaries of widget chat sessions.
 *
 * Provides statistical summaries of conversations.
 * AI-powered analysis can be added by integrating with the existing InferenceRouter.
 */
final class WidgetSummaryService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetSessionRepository $sessionRepository,
        private WidgetSummaryRepository $summaryRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate a daily summary for a widget.
     *
     * @param int $date Date in YYYYMMDD format
     */
    public function generateDailySummary(Widget $widget, int $date): WidgetSummary
    {
        $widgetId = $widget->getWidgetId();

        // Check for existing summary
        $existing = $this->summaryRepository->findByWidgetAndDate($widgetId, $date);
        if ($existing) {
            return $existing;
        }

        // Get date range for the day
        $year = (int) substr((string) $date, 0, 4);
        $month = (int) substr((string) $date, 4, 2);
        $day = (int) substr((string) $date, 6, 2);

        $fromTimestamp = mktime(0, 0, 0, $month, $day, $year);
        $toTimestamp = mktime(23, 59, 59, $month, $day, $year);

        // Get sessions for the day
        $result = $this->sessionRepository->findSessionsByWidget(
            $widgetId,
            1000,
            0,
            [
                'from' => $fromTimestamp,
                'to' => $toTimestamp,
            ]
        );

        $sessions = $result['sessions'];

        if (empty($sessions)) {
            // Create empty summary
            $summary = new WidgetSummary();
            $summary->setWidgetId($widgetId);
            $summary->setDate($date);
            $summary->setSessionCount(0);
            $summary->setMessageCount(0);
            $summary->setSummaryText('No conversations on this day.');
            $this->summaryRepository->save($summary, true);

            return $summary;
        }

        // Collect statistics
        $totalMessages = 0;
        $userMessages = 0;
        $assistantMessages = 0;

        foreach ($sessions as $session) {
            $chatId = $session->getChatId();
            if (!$chatId) {
                continue;
            }

            $chat = $this->chatRepository->find($chatId);
            if (!$chat) {
                continue;
            }

            $messages = $this->messageRepository->findChatHistory(
                $chat->getUserId(),
                $chat->getId(),
                100,
                10000
            );

            foreach ($messages as $message) {
                ++$totalMessages;
                if ('IN' === $message->getDirection()) {
                    ++$userMessages;
                } else {
                    ++$assistantMessages;
                }
            }
        }

        // Create summary entity with statistics
        $summary = new WidgetSummary();
        $summary->setWidgetId($widgetId);
        $summary->setDate($date);
        $summary->setSessionCount(count($sessions));
        $summary->setMessageCount($totalMessages);
        $summary->setTopics([]);
        $summary->setFaqs([]);
        $summary->setSentiment(['positive' => 0, 'neutral' => 100, 'negative' => 0]);
        $summary->setIssues([]);
        $summary->setRecommendations([]);
        $summary->setSummaryText(sprintf(
            '%d conversations with %d messages (%d from visitors, %d responses).',
            count($sessions),
            $totalMessages,
            $userMessages,
            $assistantMessages
        ));

        $this->summaryRepository->save($summary, true);

        $this->logger->info('Generated daily summary', [
            'widget_id' => $widgetId,
            'date' => $date,
            'sessions' => count($sessions),
            'messages' => $totalMessages,
        ]);

        return $summary;
    }

    /**
     * Get summaries for a widget.
     *
     * @return WidgetSummary[]
     */
    public function getSummaries(string $widgetId, int $limit = 7): array
    {
        return $this->summaryRepository->findRecentByWidget($widgetId, $limit);
    }

    /**
     * Get summary for a specific date.
     */
    public function getSummaryByDate(string $widgetId, int $date): ?WidgetSummary
    {
        return $this->summaryRepository->findByWidgetAndDate($widgetId, $date);
    }
}

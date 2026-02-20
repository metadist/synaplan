<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Entity\Widget;
use App\Entity\WidgetSummary;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Repository\WidgetSessionRepository;
use App\Repository\WidgetSummaryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating summaries of widget chat sessions.
 *
 * Provides AI-powered analysis of conversations including:
 * - Sentiment analysis
 * - Topic extraction
 * - FAQ identification
 * - Prompt improvement suggestions
 */
final class WidgetSummaryService
{
    public const SUMMARY_TOPIC_PREFIX = 'ws_';
    public const DEFAULT_SUMMARY_TOPIC = 'tools:widget-summary-default';
    public const DEFAULT_SUMMARY_MODEL_ID = 73;

    public function __construct(
        private EntityManagerInterface $em,
        private WidgetSessionRepository $sessionRepository,
        private WidgetSummaryRepository $summaryRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private PromptRepository $promptRepository,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Derive the summary prompt topic from a widget's ID.
     */
    public static function getSummaryTopicForWidget(Widget $widget): string
    {
        return self::SUMMARY_TOPIC_PREFIX.$widget->getWidgetId();
    }

    /**
     * Return the default summary prompt text with placeholders.
     * Used as fallback when no DB entry exists yet.
     */
    public static function getDefaultPromptText(): string
    {
        return <<<'PROMPT'
You are analyzing customer support chat conversations. Provide a structured analysis.

## CONVERSATIONS TO ANALYZE:
{{CONVERSATIONS}}

## CURRENT SYSTEM PROMPT OF THE CHATBOT:
{{SYSTEM_PROMPT}}

## YOUR TASK:
Analyze these conversations and provide insights. Answer each section separately.

### 1. EXECUTIVE SUMMARY (2-3 sentences)
What are users asking about? How well is the assistant helping them?

### 2. MAIN TOPICS (comma-separated list)
List the 3-5 main topics discussed.

### 3. SENTIMENT ANALYSIS
First, classify EVERY user message from the conversations as POSITIVE, NEUTRAL, or NEGATIVE using these rules:
- NEGATIVE: The user shows frustration, confusion, or dissatisfaction. Also classify as NEGATIVE when the assistant gave an unhelpful, incomplete, or repetitive response, when the user was bounced between AI and support agent, or when the assistant could not fulfill the request.
- NEUTRAL: Simple greetings, factual questions without emotion, basic small talk.
- POSITIVE: User expresses satisfaction, gratitude, or the assistant successfully helped them.

Then provide the percentages (MUST add up to 100):
- Positive: [number]%
- Neutral: [number]%
- Negative: [number]%

Now list ALL neutral and negative user messages with the EXACT text from the conversations.
Format each entry EXACTLY as follows (one per line):
- [NEUTRAL] User: "exact user message" | Response: "exact assistant response"
- [NEGATIVE] User: "exact user message" | Response: "exact assistant response"

### 4. FREQUENTLY ASKED QUESTIONS
List each question with how many times it was asked:
- "[question]" (asked X times)

### 5. ISSUES IDENTIFIED
What problems or gaps did you notice?
- [issue 1]
- [issue 2]

### 6. RECOMMENDATIONS
What specific improvements would help?
- [recommendation 1]
- [recommendation 2]

### 7. PROMPT IMPROVEMENT SUGGESTIONS
Based on the conversations, what should be added or changed in the system prompt?
For each suggestion, specify if it's an ADDITION (new info needed) or IMPROVEMENT (existing info needs refinement):
- ADD: [what to add and why]
- IMPROVE: [what to improve and how]
PROMPT;
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

    /**
     * Generate an AI-powered summary for specific sessions or date range.
     *
     * @param string[]|null $sessionIds Specific session IDs to analyze
     * @param int|null      $fromDate   Start date (YYYYMMDD format)
     * @param int|null      $toDate     End date (YYYYMMDD format)
     * @param int|null      $summaryId  Existing summary ID to update (for regeneration)
     *
     * @return array Summary data
     */
    public function generateCustomSummary(
        Widget $widget,
        ?array $sessionIds = null,
        ?int $fromDate = null,
        ?int $toDate = null,
        ?int $summaryId = null,
    ): array {
        $widgetId = $widget->getWidgetId();
        $ownerId = $widget->getOwnerId();

        // Collect sessions
        $sessions = [];
        if (!empty($sessionIds)) {
            $sessions = $this->sessionRepository->findBySessionIds($widgetId, $sessionIds);
        } else {
            // Build date range filters
            $filters = [];
            if ($fromDate) {
                $year = (int) substr((string) $fromDate, 0, 4);
                $month = (int) substr((string) $fromDate, 4, 2);
                $day = (int) substr((string) $fromDate, 6, 2);
                $filters['from'] = mktime(0, 0, 0, $month, $day, $year);
            }
            if ($toDate) {
                $year = (int) substr((string) $toDate, 0, 4);
                $month = (int) substr((string) $toDate, 4, 2);
                $day = (int) substr((string) $toDate, 6, 2);
                $filters['to'] = mktime(23, 59, 59, $month, $day, $year);
            }

            $result = $this->sessionRepository->findSessionsByWidget($widgetId, 1000, 0, $filters);
            $sessions = $result['sessions'];
        }

        if (empty($sessions)) {
            return [
                'sessionCount' => 0,
                'messageCount' => 0,
                'topics' => [],
                'faqs' => [],
                'sentiment' => ['positive' => 0, 'neutral' => 100, 'negative' => 0],
                'issues' => [],
                'recommendations' => [],
                'summary' => 'No conversations found for the selected criteria.',
                'promptSuggestions' => [],
            ];
        }

        // Collect all messages from sessions
        // Only include chats with 5-30 visitor messages for meaningful analysis
        $allConversations = [];
        $allMessagePairs = []; // Collect user→assistant pairs for matching sentiment messages
        $totalMessages = 0;
        $userMessages = 0;
        $assistantMessages = 0;
        $filteredSessionCount = 0;

        foreach ($sessions as $session) {
            $chatId = $session->getChatId();
            if (!$chatId) {
                continue;
            }

            $chat = $this->chatRepository->find($chatId);
            if (!$chat || $chat->getUserId() !== $ownerId) {
                continue;
            }

            // Get ALL messages without any limits (findChatHistory truncates by character count)
            $messages = $this->messageRepository->findAllByChatId($ownerId, $chatId);

            // Count visitor messages for this session (exclude system messages)
            $visitorMessageCount = 0;
            foreach ($messages as $message) {
                if ('IN' === $message->getDirection() && 'SYSTEM' !== $message->getProviderIndex()) {
                    ++$visitorMessageCount;
                }
            }

            // Filter: only include chats with 5-30 visitor messages
            if ($visitorMessageCount < 5 || $visitorMessageCount > 30) {
                continue;
            }

            ++$filteredSessionCount;
            $conversationText = [];
            $lastUserMessage = null;

            foreach ($messages as $message) {
                // Skip system messages (takeover/handback notifications)
                if ('SYSTEM' === $message->getProviderIndex()) {
                    continue;
                }

                ++$totalMessages;
                $role = 'IN' === $message->getDirection() ? 'User' : 'Assistant';
                if ('IN' === $message->getDirection()) {
                    ++$userMessages;
                    $lastUserMessage = $message->getText();
                } else {
                    ++$assistantMessages;
                    // Pair this assistant response with the last user message
                    if (null !== $lastUserMessage) {
                        $allMessagePairs[] = [
                            'userMessage' => $lastUserMessage,
                            'assistantResponse' => $message->getText(),
                        ];
                        $lastUserMessage = null;
                    }
                }
                $conversationText[] = "{$role}: {$message->getText()}";
            }

            if (!empty($conversationText)) {
                $allConversations[] = implode("\n", $conversationText);
            }
        }

        if (empty($allConversations)) {
            return [
                'sessionCount' => 0,
                'messageCount' => 0,
                'topics' => [],
                'faqs' => [],
                'sentiment' => ['positive' => 0, 'neutral' => 100, 'negative' => 0],
                'issues' => [],
                'recommendations' => [],
                'summary' => 'No qualifying conversations found. Chats must have between 5 and 30 visitor messages to be included in the analysis.',
                'promptSuggestions' => [],
            ];
        }

        // Get widget config for prompt analysis
        $config = $widget->getConfig();
        $systemPrompt = $config['systemPrompt'] ?? '';

        // Generate AI summary
        $aiSummary = $this->generateAiAnalysis($allConversations, $systemPrompt, $ownerId, $widget);

        // Replace AI-quoted responses with original full-text responses from the database.
        // The AI may truncate or paraphrase long responses; the originals are always more accurate.
        if (!empty($aiSummary['sentimentMessages']) && !empty($allMessagePairs)) {
            $aiSummary['sentimentMessages'] = $this->matchOriginalResponses(
                $aiSummary['sentimentMessages'],
                $allMessagePairs
            );
        }

        // Create or update summary entity
        if ($summaryId) {
            $summary = $this->summaryRepository->find($summaryId);
            if (!$summary || $summary->getWidgetId() !== $widgetId) {
                $summary = new WidgetSummary();
                $summary->setWidgetId($widgetId);
            }
        } else {
            $summary = new WidgetSummary();
            $summary->setWidgetId($widgetId);
        }

        $summary->setDate((int) date('Ymd'));
        $summary->setSessionCount($filteredSessionCount);
        $summary->setMessageCount($userMessages);
        $summary->setTopics($aiSummary['topics'] ?? []);
        $summary->setFaqs($aiSummary['faqs'] ?? []);
        $summary->setSentiment($aiSummary['sentiment'] ?? ['positive' => 0, 'neutral' => 100, 'negative' => 0]);
        $summary->setIssues($aiSummary['issues'] ?? []);
        $summary->setRecommendations($aiSummary['recommendations'] ?? []);
        $summary->setSummaryText($aiSummary['summary'] ?? '');
        $summary->setPromptSuggestions($aiSummary['promptSuggestions'] ?? []);
        $summary->setSentimentMessages($aiSummary['sentimentMessages'] ?? []);
        $summary->setFromDate($fromDate);
        $summary->setToDate($toDate);
        $summary->setCreated(time());

        $this->em->persist($summary);
        $this->em->flush();

        return [
            'id' => $summary->getId(),
            'date' => $summary->getDate(),
            'formattedDate' => $summary->getFormattedDate(),
            'sessionCount' => $filteredSessionCount,
            'messageCount' => $userMessages,
            'userMessages' => $userMessages,
            'assistantMessages' => $assistantMessages,
            'topics' => $aiSummary['topics'] ?? [],
            'faqs' => $aiSummary['faqs'] ?? [],
            'sentiment' => $aiSummary['sentiment'] ?? ['positive' => 0, 'neutral' => 100, 'negative' => 0],
            'issues' => $aiSummary['issues'] ?? [],
            'recommendations' => $aiSummary['recommendations'] ?? [],
            'summary' => $aiSummary['summary'] ?? '',
            'promptSuggestions' => $aiSummary['promptSuggestions'] ?? [],
            'sentimentMessages' => $aiSummary['sentimentMessages'] ?? [],
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'dateRange' => $summary->getFormattedDateRange(),
            'created' => $summary->getCreated(),
        ];
    }

    /**
     * Use AI to analyze conversations and generate insights.
     */
    private function generateAiAnalysis(array $conversations, string $systemPrompt, int $userId, Widget $widget): array
    {
        // Limit conversation text to avoid token limits
        $conversationText = implode("\n\n---\n\n", array_slice($conversations, 0, 20));
        if (strlen($conversationText) > 30000) {
            $conversationText = substr($conversationText, 0, 30000).'...';
        }

        $summaryConfig = $this->resolveSummaryConfig($widget, $conversationText, $systemPrompt);
        $analysisPrompt = $summaryConfig['prompt'];
        $modelId = $summaryConfig['modelId'];

        try {
            $aiOptions = [
                'temperature' => 0.3,
                'max_tokens' => 4000,
            ];
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
            if ($provider && $modelName) {
                $aiOptions['provider'] = $provider;
                $aiOptions['model'] = $modelName;
            }

            $result = $this->aiFacade->chat([
                ['role' => 'user', 'content' => $analysisPrompt],
            ], $userId, $aiOptions);

            $responseText = $result['text'] ?? $result['content'] ?? '';

            // Parse the structured response
            return $this->parseStructuredResponse($responseText);
        } catch (\Exception $e) {
            $this->logger->error('AI summary generation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'summary' => 'Failed to generate AI summary. Please try again.',
                'topics' => [],
                'faqs' => [],
                'sentiment' => ['positive' => 0, 'neutral' => 100, 'negative' => 0],
                'issues' => [],
                'recommendations' => [],
                'promptSuggestions' => [],
                'sentimentMessages' => [],
            ];
        }
    }

    /**
     * Resolve summary prompt and model for a widget.
     *
     * Fallback chain: custom per-widget -> system default -> hardcoded.
     *
     * @return array{prompt: string, modelId: int}
     */
    private function resolveSummaryConfig(Widget $widget, string $conversationText, string $systemPrompt): array
    {
        $promptText = null;
        $modelId = self::DEFAULT_SUMMARY_MODEL_ID;

        // 1. Try custom per-widget prompt
        $customTopic = self::getSummaryTopicForWidget($widget);
        $customPrompt = $this->promptRepository->findOneBy([
            'topic' => $customTopic,
            'ownerId' => $widget->getOwnerId(),
        ]);
        if ($customPrompt) {
            $promptText = $customPrompt->getPrompt();
            $storedModelId = (int) $customPrompt->getShortDescription();
            if ($storedModelId > 0) {
                $modelId = $storedModelId;
            }
        }

        // 2. Fallback to system default
        if (!$promptText) {
            $defaultPrompt = $this->promptRepository->findOneBy([
                'topic' => self::DEFAULT_SUMMARY_TOPIC,
                'ownerId' => 0,
            ]);
            if ($defaultPrompt) {
                $promptText = $defaultPrompt->getPrompt();
            }
        }

        // 3. Ultimate fallback: hardcoded (safety net)
        if (!$promptText) {
            $this->logger->warning('Summary prompt not found in DB, using hardcoded fallback', [
                'widget_id' => $widget->getWidgetId(),
            ]);
            $promptText = self::getDefaultPromptText();
        }

        $resolvedPrompt = str_replace(
            ['{{CONVERSATIONS}}', '{{SYSTEM_PROMPT}}'],
            [$conversationText, $systemPrompt],
            $promptText
        );

        return ['prompt' => $resolvedPrompt, 'modelId' => $modelId];
    }

    /**
     * Parse the model ID stored in a summary prompt's shortDescription field.
     */
    public static function parseModelId(Prompt $prompt): int
    {
        $raw = $prompt->getShortDescription();

        return ('' !== $raw && (int) $raw > 0) ? (int) $raw : -1;
    }

    /**
     * Parse the structured AI response into an array.
     */
    private function parseStructuredResponse(string $response): array
    {
        $result = [
            'summary' => '',
            'topics' => [],
            'faqs' => [],
            'sentiment' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
            'issues' => [],
            'recommendations' => [],
            'promptSuggestions' => [],
            'sentimentMessages' => [],
        ];

        // Extract Executive Summary (section 1)
        if (preg_match('/###?\s*1\.?\s*EXECUTIVE SUMMARY.*?\n(.*?)(?=###?\s*2\.|$)/si', $response, $m)) {
            $result['summary'] = trim(preg_replace('/^[#\s\d\.]+/', '', $m[1]));
        } elseif (preg_match('/EXECUTIVE SUMMARY.*?\n(.*?)(?=###|MAIN TOPICS|$)/si', $response, $m)) {
            $result['summary'] = trim($m[1]);
        }

        // Extract Topics (section 2)
        if (preg_match('/###?\s*2\.?\s*MAIN TOPICS.*?\n(.*?)(?=###?\s*3\.|SENTIMENT|$)/si', $response, $m)) {
            $topicsText = trim($m[1]);
            // Parse comma-separated or bullet list
            if (str_contains($topicsText, ',')) {
                $result['topics'] = array_map('trim', explode(',', $topicsText));
            } else {
                preg_match_all('/[-•*]\s*(.+)/m', $topicsText, $topicMatches);
                $result['topics'] = array_map('trim', $topicMatches[1]);
            }
            $result['topics'] = array_filter($result['topics']);
        }

        // Extract Sentiment (section 3)
        if (preg_match('/Positive:\s*(\d+)/i', $response, $m)) {
            $result['sentiment']['positive'] = (int) $m[1];
        }
        if (preg_match('/Neutral:\s*(\d+)/i', $response, $m)) {
            $result['sentiment']['neutral'] = (int) $m[1];
        }
        if (preg_match('/Negative:\s*(\d+)/i', $response, $m)) {
            $result['sentiment']['negative'] = (int) $m[1];
        }

        // Ensure sentiment sums to 100
        $total = $result['sentiment']['positive'] + $result['sentiment']['neutral'] + $result['sentiment']['negative'];
        if ($total > 0 && 100 !== $total) {
            $factor = 100 / $total;
            $result['sentiment']['positive'] = (int) round($result['sentiment']['positive'] * $factor);
            $result['sentiment']['negative'] = (int) round($result['sentiment']['negative'] * $factor);
            $result['sentiment']['neutral'] = 100 - $result['sentiment']['positive'] - $result['sentiment']['negative'];
        }

        // Extract FAQs (section 4)
        if (preg_match('/###?\s*4\.?\s*FREQUENTLY ASKED.*?\n(.*?)(?=###?\s*5\.|ISSUES|$)/si', $response, $m)) {
            $faqsText = $m[1];
            preg_match_all('/[-•*]\s*["\']?(.+?)["\']?\s*\((?:asked\s+)?(\d+)\s*times?\)/i', $faqsText, $faqMatches, PREG_SET_ORDER);
            foreach ($faqMatches as $match) {
                $result['faqs'][] = [
                    'question' => trim($match[1], " \t\n\r\0\x0B\"'"),
                    'frequency' => (int) $match[2],
                ];
            }
        }

        // Extract Issues (section 5)
        if (preg_match('/###?\s*5\.?\s*ISSUES.*?\n(.*?)(?=###?\s*6\.|RECOMMENDATIONS|$)/si', $response, $m)) {
            $issuesText = $m[1];
            preg_match_all('/[-•*]\s*(.+)/m', $issuesText, $issueMatches);
            $result['issues'] = array_map('trim', $issueMatches[1]);
        }

        // Extract Recommendations (section 6)
        if (preg_match('/###?\s*6\.?\s*RECOMMENDATIONS.*?\n(.*?)(?=###?\s*7\.|PROMPT IMPROVEMENT|$)/si', $response, $m)) {
            $recsText = $m[1];
            preg_match_all('/[-•*]\s*(.+)/m', $recsText, $recMatches);
            $result['recommendations'] = array_map('trim', $recMatches[1]);
        }

        // Extract Prompt Suggestions (section 7)
        if (preg_match('/###?\s*7\.?\s*PROMPT IMPROVEMENT.*?\n(.*?)$/si', $response, $m)) {
            $suggestionsText = $m[1];
            // Match "ADD:" or "IMPROVE:" patterns
            preg_match_all('/[-•*]?\s*(ADD|IMPROVE):\s*(.+?)(?=[-•*]?\s*(?:ADD|IMPROVE):|$)/si', $suggestionsText, $suggestionMatches, PREG_SET_ORDER);
            foreach ($suggestionMatches as $match) {
                $result['promptSuggestions'][] = [
                    'type' => strtolower(trim($match[1])),
                    'suggestion' => trim($match[2]),
                ];
            }
        }

        // Extract Sentiment-Tagged Messages (embedded in section 3, search whole response)
        preg_match_all(
            '/\[(NEUTRAL|NEGATIVE)\]\s*User:\s*"(.+?)"\s*\|\s*Response:\s*"(.+?)"/si',
            $response,
            $taggedMatches,
            PREG_SET_ORDER
        );
        foreach ($taggedMatches as $match) {
            $result['sentimentMessages'][] = [
                'sentiment' => strtolower(trim($match[1])),
                'userMessage' => trim($match[2]),
                'assistantResponse' => trim($match[3]),
            ];
        }

        // If no summary was parsed, use the full response as fallback
        if (empty($result['summary'])) {
            $result['summary'] = substr($response, 0, 500);
        }

        return $result;
    }

    /**
     * Match AI-quoted sentiment messages back to the original full-text messages.
     *
     * The AI may truncate or paraphrase long responses. This method finds the
     * best-matching original message pair and replaces the AI's quotes with the
     * actual full text from the database.
     *
     * @param array<array{sentiment: string, userMessage: string, assistantResponse: string}> $taggedMessages
     * @param array<array{userMessage: string, assistantResponse: string}>                    $originalPairs
     *
     * @return array<array{sentiment: string, userMessage: string, assistantResponse: string}>
     */
    private function matchOriginalResponses(array $taggedMessages, array $originalPairs): array
    {
        foreach ($taggedMessages as &$tagged) {
            $bestMatch = null;
            $bestScore = 0;

            foreach ($originalPairs as $pair) {
                // Try exact match first
                if ($pair['userMessage'] === $tagged['userMessage']) {
                    $bestMatch = $pair;
                    break;
                }

                // Fuzzy match: check if the AI's quoted text is contained in the original or vice versa
                $taggedNorm = mb_strtolower(trim($tagged['userMessage']));
                $originalNorm = mb_strtolower(trim($pair['userMessage']));

                if ($taggedNorm === $originalNorm) {
                    $bestMatch = $pair;
                    break;
                }

                // Substring match (AI might have trimmed the message)
                if (str_contains($originalNorm, $taggedNorm) || str_contains($taggedNorm, $originalNorm)) {
                    $score = min(mb_strlen($taggedNorm), mb_strlen($originalNorm));
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $pair;
                    }
                }

                // Similar text match for slight paraphrasing
                similar_text($taggedNorm, $originalNorm, $percent);
                if ($percent > 70 && $percent > $bestScore) {
                    $bestScore = $percent;
                    $bestMatch = $pair;
                }
            }

            if ($bestMatch) {
                $tagged['userMessage'] = $bestMatch['userMessage'];
                $tagged['assistantResponse'] = $bestMatch['assistantResponse'];
            }
        }
        unset($tagged);

        return $taggedMessages;
    }
}

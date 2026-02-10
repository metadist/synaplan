<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Repository\WidgetSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for Human Takeover functionality in Chat Widgets.
 *
 * Allows widget owners to take over conversations from the AI
 * and communicate directly with visitors in real-time via SSE.
 */
final class HumanTakeoverService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetSessionRepository $sessionRepository,
        private ChatRepository $chatRepository,
        private FileRepository $fileRepository,
        private WidgetEventCacheService $eventCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Take over a session - switch from AI to human mode.
     *
     * @throws \InvalidArgumentException if session not found or access denied
     */
    public function takeOver(string $widgetId, string $sessionId, User $operator): WidgetSession
    {
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            throw new \InvalidArgumentException('Session not found');
        }

        if ($session->isExpired()) {
            throw new \InvalidArgumentException('Session has expired');
        }

        $session->takeOver($operator->getId());

        // Create system message in the chat
        $systemMessage = 'You are now connected with a support agent.';
        $message = $this->createSystemMessage($session, $operator, $systemMessage);

        // Update session's last message preview
        $session->setLastMessage(time());
        $session->setLastMessagePreview($systemMessage);
        $this->em->flush();

        // Create event for the widget user (include messageId to prevent duplicates)
        $this->publishToSession($widgetId, $sessionId, 'takeover', [
            'mode' => 'human',
            'operatorName' => $this->getOperatorDisplayName($operator),
            'message' => $systemMessage,
            'messageId' => $message?->getId(),
            'timestamp' => time(),
        ]);

        $this->logger->info('Human takeover initiated', [
            'widget_id' => $widgetId,
            'session_id' => $sessionId,
            'operator_id' => $operator->getId(),
        ]);

        return $session;
    }

    /**
     * Hand back a session to AI.
     */
    public function handBack(string $widgetId, string $sessionId, User $operator): WidgetSession
    {
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            throw new \InvalidArgumentException('Session not found');
        }

        $session->handBackToAi();

        // Create system message in the chat
        $systemMessage = 'You are now chatting with our AI assistant.';
        $message = $this->createSystemMessage($session, $operator, $systemMessage);

        // Update session's last message preview
        $session->setLastMessage(time());
        $session->setLastMessagePreview($systemMessage);
        $this->em->flush();

        // Create event for the widget user (include messageId to prevent duplicates)
        $this->publishToSession($widgetId, $sessionId, 'handback', [
            'mode' => 'ai',
            'message' => $systemMessage,
            'messageId' => $message?->getId(),
            'timestamp' => time(),
        ]);

        $this->logger->info('Session handed back to AI', [
            'widget_id' => $widgetId,
            'session_id' => $sessionId,
            'operator_id' => $operator->getId(),
        ]);

        return $session;
    }

    /**
     * Send a message as human operator.
     * Does NOT increment message count (human messages are free).
     *
     * @param array<int> $fileIds Optional array of file IDs to attach
     */
    public function sendHumanMessage(
        string $widgetId,
        string $sessionId,
        string $text,
        User $operator,
        array $fileIds = [],
    ): Message {
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            throw new \InvalidArgumentException('Session not found');
        }

        if (!$session->isHumanMode()) {
            throw new \InvalidArgumentException('Session is not in human mode. Take over first.');
        }

        if ($session->isExpired()) {
            throw new \InvalidArgumentException('Session has expired');
        }

        $chatId = $session->getChatId();
        if (!$chatId) {
            throw new \InvalidArgumentException('No chat associated with this session');
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat) {
            throw new \InvalidArgumentException('Chat not found');
        }

        // Create the human operator's message
        // Direction is OUT (outgoing from system to visitor, like AI responses)
        $message = new Message();
        $message->setUserId($operator->getId());
        $message->setChat($chat);
        $message->setText($text);
        $message->setDirection('OUT');
        $message->setStatus('complete');
        $message->setMessageType('WDGT');
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setProviderIndex('HUMAN_OPERATOR');
        $message->setTopic('SUPPORT');
        $message->setLanguage('en');

        $this->em->persist($message);

        // Attach files if provided
        $attachedFiles = [];
        if (!empty($fileIds)) {
            foreach ($fileIds as $fileId) {
                $file = $this->fileRepository->find($fileId);
                if ($file && $file->getUserId() === $operator->getId()) {
                    $message->addFile($file);
                    $file->setStatus('attached');
                    $attachedFiles[] = [
                        'id' => $file->getId(),
                        'filename' => $file->getFileName(),
                        'mimeType' => $file->getFileMime(),
                        'size' => $file->getFileSize(),
                    ];
                }
            }
            if (!empty($attachedFiles)) {
                $message->setFile(count($attachedFiles));
            }
        }

        // Update session's last message time and preview (but NOT message count)
        $session->setLastMessage(time());
        $session->setLastMessagePreview($text);
        $session->updateLastHumanActivity();

        // Update chat timestamp
        $chat->updateTimestamp();

        $this->em->flush();

        // Publish message event to widget
        $eventData = [
            'direction' => 'OUT',
            'text' => $text,
            'messageId' => $message->getId(),
            'timestamp' => $message->getUnixTimestamp(),
            'sender' => 'human',
            'operatorName' => $this->getOperatorDisplayName($operator),
        ];

        // Include file info in event if files were attached
        if (!empty($attachedFiles)) {
            $eventData['files'] = $attachedFiles;
        }

        $this->publishToSession($widgetId, $sessionId, 'message', $eventData);

        $this->logger->info('Human message sent', [
            'widget_id' => $widgetId,
            'session_id' => $sessionId,
            'operator_id' => $operator->getId(),
            'message_id' => $message->getId(),
            'file_count' => count($attachedFiles),
        ]);

        return $message;
    }

    /**
     * Set session to waiting for human response.
     * Called when AI is disabled but operator hasn't responded yet.
     */
    public function setWaitingForHuman(string $widgetId, string $sessionId): WidgetSession
    {
        $session = $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId);

        if (!$session) {
            throw new \InvalidArgumentException('Session not found');
        }

        $session->setWaitingForHuman();
        $this->em->flush();

        return $session;
    }

    /**
     * Notify widget owner about a new message in waiting session.
     */
    public function notifyOperator(string $widgetId, int $operatorId, string $sessionId, string $messagePreview): void
    {
        $this->eventCache->publishNotification($widgetId, [
            'sessionId' => $sessionId,
            'preview' => mb_substr($messagePreview, 0, 100),
            'timestamp' => time(),
        ]);
    }

    /**
     * Publish event to a specific session via cache.
     *
     * @param array<string, mixed> $data
     */
    private function publishToSession(string $widgetId, string $sessionId, string $type, array $data): void
    {
        $this->eventCache->publish($widgetId, $sessionId, $type, $data);
    }

    /**
     * Get a display name for the operator.
     * Uses name from user details or falls back to email or 'Support'.
     */
    private function getOperatorDisplayName(User $operator): string
    {
        $userDetails = $operator->getUserDetails();

        // Check for name in user details
        if (!empty($userDetails['name'])) {
            return $userDetails['name'];
        }

        // Fall back to email (extract part before @)
        $email = $operator->getMail();
        if ($email) {
            $atPos = strpos($email, '@');
            if (false !== $atPos) {
                return ucfirst(substr($email, 0, $atPos));
            }

            return $email;
        }

        return 'Support';
    }

    /**
     * Create a system message in the chat (for takeover/handback notifications).
     */
    private function createSystemMessage(WidgetSession $session, User $operator, string $text): ?Message
    {
        $chatId = $session->getChatId();
        if (!$chatId) {
            return null;
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat) {
            return null;
        }

        $message = new Message();
        $message->setUserId($operator->getId());
        $message->setChat($chat);
        $message->setText($text);
        $message->setDirection('OUT');
        $message->setStatus('complete');
        $message->setMessageType('WDGT');
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setProviderIndex('SYSTEM');

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}

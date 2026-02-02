<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Entity\WidgetEvent;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\WidgetEventRepository;
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
        private WidgetEventRepository $eventRepository,
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
        $this->em->flush();

        // Create event for the widget user
        $this->publishToSession($widgetId, $sessionId, 'takeover', [
            'mode' => 'human',
            'operatorName' => $this->getOperatorDisplayName($operator),
            'message' => 'You are now connected with a support agent.',
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
        $this->em->flush();

        // Create event for the widget user
        $this->publishToSession($widgetId, $sessionId, 'handback', [
            'mode' => 'ai',
            'message' => 'You are now chatting with our AI assistant.',
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
     */
    public function sendHumanMessage(
        string $widgetId,
        string $sessionId,
        string $text,
        User $operator
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

        // Update session's last message time and preview (but NOT message count)
        $session->setLastMessage(time());
        $session->setLastMessagePreview($text);
        $session->updateLastHumanActivity();

        // Update chat timestamp
        $chat->updateTimestamp();

        $this->em->flush();

        // Publish message event to widget
        $this->publishToSession($widgetId, $sessionId, 'message', [
            'direction' => 'OUT',
            'text' => $text,
            'messageId' => $message->getId(),
            'timestamp' => $message->getUnixTimestamp(),
            'sender' => 'human',
            'operatorName' => $this->getOperatorDisplayName($operator),
        ]);

        $this->logger->info('Human message sent', [
            'widget_id' => $widgetId,
            'session_id' => $sessionId,
            'operator_id' => $operator->getId(),
            'message_id' => $message->getId(),
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
        $event = new WidgetEvent();
        $event->setWidgetId($widgetId);
        $event->setSessionId('notifications'); // Special session ID for notifications
        $event->setType('notification');
        $event->setPayload([
            'sessionId' => $sessionId,
            'preview' => mb_substr($messagePreview, 0, 100),
            'timestamp' => time(),
        ]);

        $this->eventRepository->save($event, true);
    }

    /**
     * Publish event to a specific session.
     *
     * @param array<string, mixed> $data
     */
    private function publishToSession(string $widgetId, string $sessionId, string $type, array $data): void
    {
        $event = new WidgetEvent();
        $event->setWidgetId($widgetId);
        $event->setSessionId($sessionId);
        $event->setType($type);
        $event->setPayload($data);

        $this->eventRepository->save($event, true);
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
            if ($atPos !== false) {
                return ucfirst(substr($email, 0, $atPos));
            }

            return $email;
        }

        return 'Support';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Service\Widget\WidgetHandoff;
use App\Service\Widget\WidgetRealtimeBroadcaster;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Second stage of a widget turn: stores the visitor's message in the
 * session's chat. The chat is the session's own, else the one the visitor
 * names, else a new one that opens with the configured welcome. The message
 * reaches the dashboard live, a trigger phrase pages a person, and only the
 * session's own uploads are attached. The session's counter, preview and
 * chat link share one flush, the hot path's last.
 */
final readonly class WidgetConversation
{
    public function __construct(
        private ChatRepository $chats,
        private FileRepository $files,
        private WidgetSessionService $sessions,
        private WidgetRealtimeBroadcaster $broadcaster,
        private WidgetHandoff $handoff,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function store(WidgetTurn $turn): StoredVisitorMessage
    {
        $chat = $this->chat($turn);

        $message = new Message();
        $message->setUserId($turn->owner->getId());
        $message->setChat($chat);
        $message->setText($turn->text);
        $message->setDirection('IN');
        $message->setStatus('processing');
        $message->setMessageType('WDGT');
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setProviderIndex('widget');
        $this->em->persist($message);
        $this->em->flush();

        $this->broadcaster->publishSessionEvent($turn->widget->getWidgetId(), $turn->session->getSessionId(), 'message', [
            'direction' => 'IN',
            'text' => $turn->text,
            'messageId' => $message->getId(),
            'timestamp' => $message->getUnixTimestamp(),
            'sender' => 'user',
        ]);

        $this->pageOnTriggerPhrase($turn);
        $fileIds = $this->attachFiles($turn, $message);

        $this->sessions->incrementMessageCount($turn->session, false);
        $turn->session->setLastMessagePreview($turn->text);
        $this->sessions->attachChat($turn->session, $chat, false);
        $this->em->flush();

        return new StoredVisitorMessage($chat, $message, $fileIds);
    }

    /**
     * A person runs the session: the message waits for them (a human session
     * turns to waiting), every operator's dashboard hears of it, and the
     * visitor gets no AI answer.
     *
     * @return array<string, mixed> the answer the widget reads
     */
    public function awaitOperator(WidgetTurn $turn, StoredVisitorMessage $stored): array
    {
        $session = $turn->session;
        $stored->message->setStatus('complete');
        $session->setLastMessage(time());
        $session->setLastMessagePreview($turn->text);
        if (WidgetSession::MODE_HUMAN === $session->getMode()) {
            $session->setWaitingForHuman();
        }
        $this->em->flush();

        $this->broadcaster->publishOperatorNotification($turn->widget->getWidgetId(), [
            'kind' => 'new_message',
            'sessionId' => $session->getSessionId(),
            'preview' => mb_substr($turn->text, 0, 100),
            'timestamp' => time(),
        ]);
        $this->sessions->generateTitleIfNeeded($session, $turn->owner->getId());

        $this->logger->info('Widget message saved in human mode (no AI processing)', [
            'widget_id' => $turn->widget->getWidgetId(),
            'session_id' => $session->getSessionId(),
            'mode' => $session->getMode(),
        ]);

        return ['success' => true, 'chatId' => $stored->chat->getId(), 'mode' => $session->getMode(), 'text' => ''];
    }

    private function chat(WidgetTurn $turn): Chat
    {
        $chat = $this->ownedChat($turn, $turn->session->getChatId()) ?? $this->ownedChat($turn, $turn->chatId);
        if (null !== $chat) {
            $chat->updateTimestamp();
            $this->em->flush();

            return $chat;
        }

        $now = new \DateTime();
        $chat = new Chat();
        $chat->setUserId($turn->owner->getId());
        $chat->setTitle(sprintf('Widget: %s • %s', $turn->widget->getName(), substr($turn->session->getSessionId(), -6)));
        $chat->setSource('widget');
        $chat->setCreatedAt($now);
        $chat->setUpdatedAt($now);
        $this->em->persist($chat);
        $this->em->flush();

        $this->welcome($turn, $chat);

        $this->logger->info('Widget chat created', [
            'widget_id' => $turn->widget->getWidgetId(),
            'chat_id' => $chat->getId(),
            'session_id' => $turn->session->getSessionId(),
        ]);

        return $chat;
    }

    private function ownedChat(WidgetTurn $turn, ?int $chatId): ?Chat
    {
        $chat = $chatId ? $this->chats->find($chatId) : null;

        return $chat instanceof Chat && $chat->getUserId() === $turn->owner->getId() ? $chat : null;
    }

    /**
     * The configured welcome opens a new chat ten seconds before the visitor's
     * message (ties sort by id), and counts toward the session's total.
     */
    private function welcome(WidgetTurn $turn, Chat $chat): void
    {
        $autoMessage = $turn->config['autoMessage'] ?? '';
        if (empty($autoMessage)) {
            return;
        }

        $timestamp = time() - 10;
        $welcome = new Message();
        $welcome->setUserId($turn->owner->getId());
        $welcome->setChat($chat);
        $welcome->setText((string) $autoMessage);
        $welcome->setDirection('OUT');
        $welcome->setStatus('complete');
        $welcome->setMessageType('WDGT');
        $welcome->setTrackingId($timestamp);
        $welcome->setUnixTimestamp($timestamp);
        $welcome->setDateTime(date('YmdHis', $timestamp));
        $welcome->setProviderIndex('widget');
        $this->em->persist($welcome);
        $this->em->flush();

        $turn->session->incrementMessageCount();
        $this->em->flush();

        $this->logger->info('Widget welcome message created', [
            'widget_id' => $turn->widget->getWidgetId(),
            'chat_id' => $chat->getId(),
        ]);
    }

    /**
     * The visitor still gets an AI answer: the dashboard already shows the
     * session as needing a person, and the conversation keeps moving.
     */
    private function pageOnTriggerPhrase(WidgetTurn $turn): void
    {
        $webhookUrl = (string) ($turn->config['slackWebhookUrl'] ?? '');
        if ('' === $webhookUrl || WidgetSession::MODE_AI !== $turn->session->getMode()) {
            return;
        }

        $triggers = is_array($turn->config['humanHandoffTriggers'] ?? null)
            ? array_values(array_filter($turn->config['humanHandoffTriggers'], 'is_string'))
            : [];
        $trigger = WidgetHandoff::matchedTrigger($turn->text, $triggers);
        if (null !== $trigger) {
            $this->handoff->page($turn->widget, $turn->session, $webhookUrl, $turn->text, sprintf('matched phrase: "%s"', $trigger));
        }
    }

    /**
     * @return list<int> the attached file ids
     */
    private function attachFiles(WidgetTurn $turn, Message $message): array
    {
        $fileIds = [];
        foreach ($turn->files as $fileId) {
            $fileId = (int) $fileId;
            $file = $fileId > 0 ? $this->files->find($fileId) : null;
            if (null === $file || $file->getUserSessionId() !== $turn->session->getId()) {
                continue;
            }

            $message->addFile($file);
            $file->setStatus('attached');
            $fileIds[] = $fileId;
        }

        if ([] !== $fileIds) {
            $message->setFile(count($fileIds));
            $this->em->flush();
        }

        return $fileIds;
    }
}

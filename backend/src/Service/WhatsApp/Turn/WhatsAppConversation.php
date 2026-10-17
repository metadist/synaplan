<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Service\Email\EmailChatService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Second stage of a WhatsApp turn: stores the message in the sender's
 * WhatsApp chat with its metadata and media (fetched and, for audio and
 * video, transcribed by WhatsAppService), moves the chat to the top of the
 * list and tells open browsers. Media that cannot be fetched is told to the
 * sender and ends the turn.
 */
final readonly class WhatsAppConversation
{
    public function __construct(
        private WhatsAppService $whatsApp,
        private EmailChatService $emailChatService,
        private EntityManagerInterface $em,
        private DiscordNotificationService $discord,
        private ?ChatActivityNotifier $chatActivityNotifier = null,
    ) {
    }

    /**
     * @throws WhatsAppMessageAnswered when the message's media cannot be fetched
     */
    public function store(InboundWhatsApp $turn): StoredWhatsAppMessage
    {
        $dto = $turn->dto;
        $user = $turn->user;
        $mediaId = WhatsAppInboundContent::extractMediaId($dto);
        $mediaUrl = $dto->incomingMsg[$dto->type]['link'] ?? null;
        $chat = $this->emailChatService->findOrCreateWhatsAppChat($user, $dto->from);

        // The chat owner's id, so ownership checks that join on the chat's user
        // line up; the effective user only runs the media paths.
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setChat($chat);
        $message->setTrackingId($dto->timestamp);
        $message->setProviderIndex('WHATSAPP');
        $message->setUnixTimestamp($dto->timestamp);
        $message->setDateTime(date('YmdHis', $dto->timestamp));
        $message->setMessageType('WTSP');
        $message->setFile(0);
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setText(WhatsAppInboundContent::extractMessageText($dto));
        $message->setDirection('IN');
        $message->setStatus('processing');
        $this->em->persist($message);
        // The metadata and media need the message id.
        $this->em->flush();

        $message->setMeta('whatsapp_input_type', $dto->type);
        $message->setMeta('whatsapp_should_send_audio', $turn->answersBySpeech ? '1' : '0');
        $this->storeMessageMetadata($message, $dto, $user);
        $mediaDownloadError = $mediaId ? $this->whatsApp->handleMediaDownload($message, $dto, $mediaId, $mediaUrl, $turn->effectiveUserId) : null;

        // The inbound message moves the chat to the top of the list, on reload
        // and live in an open browser (#1372).
        $chat->updateTimestamp();
        $this->em->flush();
        $this->chatActivityNotifier?->publishActivity($chat, $user->getId(), 'IN', $message->getText());

        if ($mediaDownloadError) {
            $this->sendErrorMessage($dto, $mediaDownloadError);
            $this->discord->notifyWhatsAppError(
                'media_download',
                $dto->from,
                $message->getText() ?: '[Media message]',
                $mediaDownloadError,
                [
                    'message_type' => $dto->type,
                    'file_type' => $dto->incomingMsg[$dto->type]['mime_type'] ?? 'unknown',
                ],
                $user->getId(),
            );

            throw WhatsAppMessageAnswered::with(['success' => false, 'message_id' => $dto->messageId, 'error' => $mediaDownloadError]);
        }

        return new StoredWhatsAppMessage($chat, $message);
    }

    private function storeMessageMetadata(Message $message, IncomingMessageDto $dto, User $user): void
    {
        $message->setMeta('channel', 'whatsapp');
        $message->setMeta('from_phone', $dto->from);
        $message->setMeta('original_user_id', (string) $user->getId());
        $message->setMeta('to_phone_number_id', $dto->phoneNumberId);
        if ($dto->displayPhoneNumber) {
            $message->setMeta('to_display_phone', $dto->displayPhoneNumber);
        }
        $message->setMeta('external_id', $dto->messageId);
        $message->setMeta('message_type', $dto->type);

        if (!empty($dto->value['contacts'][0]['profile']['name'])) {
            $message->setMeta('profile_name', $dto->value['contacts'][0]['profile']['name']);
        }
    }

    /**
     * Send a user-friendly error message via WhatsApp.
     */
    private function sendErrorMessage(IncomingMessageDto $dto, string $error): void
    {
        $errorMap = [
            'transcription' => "⚠️ *Sprachnachricht konnte nicht verarbeitet werden*\n\nDie Sprachnachricht konnte nicht transkribiert werden. Bitte versuche es erneut oder sende eine Textnachricht.",
            'image' => "⚠️ *Bild konnte nicht analysiert werden*\n\nDas Bild konnte nicht verarbeitet werden. Bitte versuche es erneut.",
            'video' => "⚠️ *Video konnte nicht verarbeitet werden*\n\nDas Video konnte nicht analysiert werden. Bitte versuche es erneut.",
            'no_audio' => "⚠️ *Video ohne Audiospur*\n\nDas Video enthält keine Audiospur, die transkribiert werden kann.",
            'file_too_large' => "⚠️ *Datei zu groß*\n\nDie Datei ist zu groß (max. 128 MB). Bitte sende eine kleinere Datei.",
            'unsupported_format' => "⚠️ *Format nicht unterstützt*\n\nDieses Dateiformat wird nicht unterstützt.",
            'default' => "⚠️ *Fehler bei der Verarbeitung*\n\nDeine Nachricht konnte nicht verarbeitet werden. Bitte versuche es erneut.",
        ];

        // Determine error type
        $errorLower = strtolower($error);
        $messageTemplate = $errorMap['default'];

        if (str_contains($errorLower, 'transcri') || str_contains($errorLower, 'whisper') || str_contains($errorLower, 'audio')) {
            $messageTemplate = $errorMap['transcription'];
        } elseif (str_contains($errorLower, 'image') || str_contains($errorLower, 'vision')) {
            $messageTemplate = $errorMap['image'];
        } elseif (str_contains($errorLower, 'video')) {
            $messageTemplate = $errorMap['video'];
        } elseif (str_contains($errorLower, 'no audio') || str_contains($errorLower, 'audio track')) {
            $messageTemplate = $errorMap['no_audio'];
        } elseif (str_contains($errorLower, 'too large') || str_contains($errorLower, 'size')) {
            $messageTemplate = $errorMap['file_too_large'];
        } elseif (str_contains($errorLower, 'format') || str_contains($errorLower, 'unsupported')) {
            $messageTemplate = $errorMap['unsupported_format'];
        }

        $this->whatsApp->sendMessage($dto->from, $messageTemplate, $dto->phoneNumberId);
    }
}

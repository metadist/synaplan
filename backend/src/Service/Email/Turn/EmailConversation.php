<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Entity\Message;
use App\Service\Email\EmailChatService;
use App\Service\Email\InboundEmailAttachmentStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Second stage of an inbound email turn: stores the email in the sender's
 * email chat (by keyword or thread) with the subject leading the text, the
 * addresses, fingerprint, keyword and message id as metadata, and the
 * relay's attachments fetched onto it. An audio attachment asks for a spoken
 * reply.
 */
final readonly class EmailConversation
{
    public function __construct(
        private EmailChatService $emailChats,
        private InboundEmailAttachmentStore $attachments,
        private EntityManagerInterface $em,
    ) {
    }

    public function store(InboundEmail $email): StoredInboundEmail
    {
        $chat = $this->emailChats->findOrCreateChatContext($email->user, $email->keyword, $email->subject, $email->thread->inReplyTo);

        // setChat(), not setChatId(): BCHATID is mapped twice, and the id alone can persist NULL.
        $message = new Message();
        $message->setUserId($email->user->getId());
        $message->setChat($chat);
        $message->setTrackingId(time());
        $message->setProviderIndex('EMAIL');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('MAIL');
        $message->setFile(0);
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setText(!empty($email->subject) && '(no subject)' !== $email->subject ? 'Subject: '.$email->subject."\n\n".$email->body : $email->body);
        $message->setDirection('IN');
        $message->setStatus('processing');
        $this->em->persist($message);
        // The metadata needs the message id.
        $this->em->flush();

        $message->setMeta('channel', 'email');
        $message->setMeta('from_email', $email->fromEmail);
        $message->setMeta('to_email', $email->toEmail);
        $message->setMeta('email_fingerprint', $email->fingerprint);
        if ($email->keyword) {
            $message->setMeta('email_keyword', $email->keyword);
        }
        if (!empty($email->subject)) {
            $message->setMeta('email_subject', $email->subject);
        }
        if ($email->thread->externalId) {
            $message->setMeta('external_id', $email->thread->externalId);
        }
        if ([] !== $email->attachments) {
            $message->setMeta('has_attachments', 'true');
            $this->attachments->attach($message, $email->user, $email->attachments);
            if (self::hasAudio($email->attachments)) {
                $message->setMeta('voice_reply', '1');
            }
        }
        $this->em->flush();

        return new StoredInboundEmail($chat, $message);
    }

    /**
     * @param array<mixed> $attachments
     */
    private static function hasAudio(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            $mime = is_array($attachment) ? ($attachment['content_type'] ?? '') : '';
            if (is_string($mime) && str_starts_with($mime, 'audio/')) {
                return true;
            }
        }

        return false;
    }
}

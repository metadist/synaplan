<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\EmailChatService;
use App\Service\Email\EmailWebhookIdempotencyService;
use App\Service\Email\InternalEmailService;
use App\Service\Email\RawMimeEmailParser;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First stage of an inbound email turn: accepts the relay's email or
 * answers it without a turn. In order: the payload, a raw MIME body read as
 * its text (#1077), the keyword address, the idempotency check (a redelivery
 * is answered as the duplicate it is, never twice), the sender's account
 * (an unknown sender gets an anonymous one) and the account's message limit.
 * A refused sender and a spent limit are told by mail.
 */
final readonly class InboundEmailIntake
{
    public function __construct(
        private RawMimeEmailParser $rawMime,
        private EmailWebhookIdempotencyService $idempotency,
        private EmailChatService $emailChats,
        private RateLimitService $rateLimits,
        private InternalEmailService $mailer,
        private DiscordNotificationService $discord,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InboundEmailAnswered
     */
    public function accept(Request $request): InboundEmail
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            throw InboundEmailAnswered::json(['success' => false, 'error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($data) || empty($data['from']) || empty($data['to']) || empty($data['body']) || !is_scalar($data['body'])) {
            throw InboundEmailAnswered::json(['success' => false, 'error' => 'Missing required fields: from, to, body'], Response::HTTP_BAD_REQUEST);
        }

        $fromEmail = strtolower(trim((string) $data['from']));
        $toEmail = strtolower(trim((string) $data['to']));
        $subject = self::text($data['subject'] ?? null) ?? '(no subject)';
        $body = $this->body((string) $data['body']);
        $messageId = self::text($data['message_id'] ?? null);
        $reportsToDiscord = str_ends_with($fromEmail, InboundEmail::DEBUG_DOMAIN);

        $idempotency = $this->idempotency->findDuplicate($fromEmail, $toEmail, $subject, $body, $messageId);
        $keyword = $this->emailChats->parseEmailKeyword($toEmail);
        $this->logger->info('Email webhook received', [
            'from' => $fromEmail,
            'to' => $toEmail,
            'keyword' => $keyword,
            'subject' => $subject,
            'body_length' => strlen($body),
        ]);
        if (null !== $idempotency['existing']) {
            throw $this->duplicate($idempotency['existing'], $fromEmail, $toEmail, $subject, $idempotency['normalized_message_id']);
        }

        $account = $this->emailChats->findOrCreateUserFromEmail($fromEmail);
        if (isset($account['error'])) {
            throw $this->refuseSender((string) $account['error'], $fromEmail, $toEmail, $subject, $messageId, $reportsToDiscord);
        }
        $this->checkLimit($account['user'], $fromEmail, $toEmail, $subject, $messageId, $reportsToDiscord);

        return new InboundEmail(
            fromEmail: $fromEmail,
            toEmail: $toEmail,
            subject: $subject,
            body: $body,
            thread: new EmailThread($messageId, self::text($data['in_reply_to'] ?? null), $idempotency['normalized_message_id']),
            keyword: $keyword,
            fingerprint: $idempotency['fingerprint'],
            attachments: !empty($data['attachments']) && is_array($data['attachments']) ? $data['attachments'] : [],
            user: $account['user'],
            isAnonymous: $account['is_anonymous'] ?? false,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Some relays forward the untouched MIME source instead of the text part;
     * stored as-is, the AI would never see the question.
     */
    private function body(string $body): string
    {
        if (!$this->rawMime->looksLikeRawMime($body)) {
            return $body;
        }

        $parsed = $this->rawMime->extractText($body);
        if ('' === trim($parsed)) {
            $this->logger->warning('Email webhook: body looked like raw MIME but no text could be extracted', ['raw_length' => strlen($body)]);

            return $body;
        }
        $this->logger->info('Email webhook: parsed raw MIME body into plain text', ['raw_length' => strlen($body), 'parsed_length' => strlen($parsed)]);

        return $parsed;
    }

    private function duplicate(Message $existing, string $fromEmail, string $toEmail, string $subject, ?string $externalId): InboundEmailAnswered
    {
        if ($externalId) {
            $this->logger->warning('Duplicate email webhook detected; skipping processing', [
                'external_message_id' => $externalId,
                'from' => $fromEmail,
                'existing_message_id' => $existing->getId(),
            ]);
        } else {
            $this->logger->warning('Duplicate email webhook detected via fingerprint; skipping processing', [
                'from' => $fromEmail,
                'existing_message_id' => $existing->getId(),
            ]);
        }
        $this->discord->notifyDuplicateEmailWebhook(
            fromEmail: $fromEmail,
            toEmail: $toEmail,
            subject: $subject,
            existingMessageId: (int) $existing->getId(),
            chatId: $existing->getChatId(),
            externalMessageId: $externalId ?: null,
            detectionMethod: $externalId ? 'external_id' : 'fingerprint',
            userId: $existing->getUserId(),
        );

        return InboundEmailAnswered::json([
            'success' => true,
            'duplicate' => true,
            'message_id' => $existing->getId(),
            'chat_id' => $existing->getChatId(),
        ], Response::HTTP_OK);
    }

    private function refuseSender(string $error, string $fromEmail, string $toEmail, string $subject, ?string $messageId, bool $reportsToDiscord): InboundEmailAnswered
    {
        $this->logger->warning('Email rejected', ['email' => $fromEmail, 'reason' => $error]);
        if ($reportsToDiscord) {
            $this->discord->notifyEmailError('user_creation', $fromEmail, $toEmail, $subject, $error);
        }
        $this->tell($fromEmail, $subject, $error, $messageId, $toEmail, 'Failed to send rejection notification email');

        return InboundEmailAnswered::json(['success' => false, 'error' => $error], Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function checkLimit(User $user, string $fromEmail, string $toEmail, string $subject, ?string $messageId, bool $reportsToDiscord): void
    {
        $check = $this->rateLimits->checkLimit($user, 'MESSAGES');
        if ($check['allowed']) {
            return;
        }

        if ($reportsToDiscord) {
            $this->discord->notifyEmailError('rate_limit', $fromEmail, $toEmail, $subject, "Limit: {$check['limit']}, Used: {$check['used']}");
        }
        $notice = 'Rate limit exceeded. Please try again later.';
        if (!empty($check['reset_at'])) {
            $notice .= "\n\nYour limit will reset at: ".$check['reset_at'];
        }
        $this->tell($fromEmail, $subject, $notice, $messageId, $toEmail, 'Failed to send rate limit notification email');

        throw InboundEmailAnswered::json(['success' => false, 'error' => 'Rate limit exceeded', 'limit' => $check['limit'], 'used' => $check['used'], 'reset_at' => $check['reset_at'] ?? null], Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * The sender hears why no answer comes; a mail that cannot go out only
     * leaves a log line.
     */
    private function tell(string $to, string $subject, string $text, ?string $messageId, string $recipient, string $failureLog): void
    {
        try {
            $this->mailer->sendAiResponseEmail($to, $subject, $text, $messageId, originalRecipient: $recipient);
        } catch (\Exception $e) {
            $this->logger->error($failureLog, ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}

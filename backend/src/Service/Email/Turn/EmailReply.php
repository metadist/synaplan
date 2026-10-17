<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Service\Email\InternalEmailService;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ExternalReplyReferences;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\MessageProcessor;
use App\Service\Notification\DiscordNotificationService;
use Psr\Log\LoggerInterface;

/**
 * Last stage of an inbound email turn: the answer, by mail. The processor
 * answers the stored email; its usage is recorded, a spoken reply is added
 * when the email asked for one, the answer is stored, and the reply goes to
 * the sender in the same thread with its files and doc links resolved. A
 * failed answer still tells the sender by mail, and the relay is never
 * asked to deliver the email again.
 */
final readonly class EmailReply
{
    public function __construct(
        private MessageProcessor $processor,
        private EmailVoiceReply $voice,
        private EmailAnswerStore $answers,
        private EmailReplyAttachments $files,
        private InternalEmailService $mailer,
        private ExternalReplyReferences $references,
        private ChatErrorPresenter $errors,
        private DiscordNotificationService $discord,
        private LoggerInterface $logger,
        private ?GeneratedMediaTextRenderer $mediaText = null,
    ) {
    }

    /**
     * @return array<string, mixed> the answer to the relay
     */
    public function answer(InboundEmail $email, StoredInboundEmail $stored): array
    {
        $startTime = microtime(true);
        $result = $this->processor->process($stored->message);
        $processingTime = microtime(true) - $startTime;
        if (!($result['success'] ?? false)) {
            return $this->answerFailed($email, $stored, $result, $processingTime);
        }

        $answer = (string) ($result['response']['content'] ?? '');
        $metadata = $result['response']['metadata'] ?? [];
        $attachmentPath = $this->files->primary($metadata);
        $usage = $this->answers->recordUsage($email, $stored, $answer, $metadata, $processingTime);
        if (null === $attachmentPath && '1' === $stored->message->getMeta('voice_reply')) {
            $attachmentPath = $this->voice->attachment($email, $stored->message, $answer, $result);
        }
        $this->answers->store($email, $stored, $answer, $result, $usage);
        $this->send($email, $stored, $answer, $metadata, $processingTime, $attachmentPath);

        if ($email->reportsToDiscord()) {
            $this->discord->notifyEmailSuccess($email->fromEmail, $email->toEmail, $email->subject, $email->body, $answer, [
                'provider' => $metadata['provider'] ?? null,
                'model' => $metadata['model'] ?? null,
                'processing_time' => $processingTime,
                'message_id' => $stored->message->getId(),
                'chat_id' => $stored->chat->getId(),
            ]);
        }

        return [
            'success' => true,
            'message_id' => $stored->message->getId(),
            'chat_id' => $stored->chat->getId(),
            'response' => ['text' => $answer, 'metadata' => $metadata],
            'user_info' => ['is_anonymous' => $email->isAnonymous, 'rate_limit_level' => $email->user->getRateLimitLevel()],
        ];
    }

    /**
     * Reports a turn that failed after the intake.
     *
     * @return array<string, mixed> the answer to the relay
     */
    public function failure(InboundEmail $email, \Exception $e): array
    {
        $this->logger->error('Email webhook processing failed', [
            'from' => $email->fromEmail,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        if ($email->reportsToDiscord()) {
            $this->discord->notifyEmailError('processing', $email->fromEmail, $email->toEmail, $email->subject, $e->getMessage()."\n".$e->getTraceAsString(), ['user_message' => $email->body]);
        }

        return [
            'success' => false,
            'error' => 'Internal error processing email',
            'details' => 'dev' === ($_ENV['APP_ENV'] ?? null) ? $e->getMessage() : null,
        ];
    }

    /**
     * The sender already has the error by mail, so the relay is told the
     * outcome without being asked to deliver again.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function answerFailed(InboundEmail $email, StoredInboundEmail $stored, array $result, float $processingTime): array
    {
        $view = $this->errors->presentFromResult($result, $stored->message->getLanguage() ?: 'en', false);
        if ($email->reportsToDiscord()) {
            $this->discord->notifyEmailError('processing', $email->fromEmail, $email->toEmail, $email->subject, $view->rawMessage, ['user_message' => $email->body]);
        }

        try {
            $this->mailer->sendAiResponseEmail($email->fromEmail, $email->subject, $view->userText, $email->thread->messageId, $result['provider'] ?? null, null, $processingTime, null, $email->toEmail);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email error response', ['to' => $email->fromEmail, 'error' => $e->getMessage()]);
        }

        return ['success' => false, 'error' => 'Message processing failed'];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function send(InboundEmail $email, StoredInboundEmail $stored, string $answer, array $metadata, float $processingTime, ?string $attachmentPath): void
    {
        $language = $stored->message->getLanguage();
        $text = null !== $this->mediaText
            ? $this->mediaText->forUser($answer, $language, $email->user->getLocale())
            : GeneratedMediaTextRenderer::renderModel($answer);
        $provider = $metadata['provider'] ?? null;
        $model = $metadata['model'] ?? null;

        try {
            $this->mailer->sendAiResponseEmail(
                $email->fromEmail,
                $email->subject,
                $this->references->resolve($text, $email->user),
                $email->thread->messageId,
                $provider,
                $model,
                $processingTime,
                $attachmentPath,
                $email->toEmail,
                $metadata['media_type'] ?? null,
                $this->files->additional($metadata),
                $language ?: $email->user->getLocale(),
            );
            $this->logger->info('Email response sent', [
                'to' => $email->fromEmail,
                'subject' => $email->subject,
                'provider' => $provider,
                'model' => $model,
                'processing_time' => $processingTime,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email response', ['to' => $email->fromEmail, 'error' => $e->getMessage()]);
            if ($email->reportsToDiscord()) {
                $this->discord->notifyEmailError('send_failed', $email->fromEmail, $email->toEmail, $email->subject, $e->getMessage(), ['user_message' => $email->body]);
            }
        }
    }
}

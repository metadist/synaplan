<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\ModelConfigService;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * The spoken reply an email with an audio attachment asks for: the answer as
 * a reader would hear it, synthesized as an MP3 in the turn's language
 * (#2283) with the owner's speech model. An answer with nothing to speak, or
 * a synthesis that fails, leaves the reply as text.
 */
final readonly class EmailVoiceReply
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
        private RateLimitService $rateLimits,
        private EmailReplyAttachments $files,
        private DiscordNotificationService $discord,
        private LoggerInterface $logger,
        private ?GeneratedMediaTextRenderer $mediaText = null,
    ) {
    }

    /**
     * @param array<string, mixed> $result the processor's result
     *
     * @return string|null the MP3's absolute path
     */
    public function attachment(InboundEmail $email, Message $message, string $answer, array $result): ?string
    {
        $path = null;
        try {
            $language = is_string($result['classification']['language'] ?? null)
                ? (string) $result['classification']['language']
                : ($message->getLanguage() ?: 'en');
            $speakable = null !== $this->mediaText
                ? $this->mediaText->forUser($answer, $message->getLanguage(), $email->user->getLocale())
                : GeneratedMediaTextRenderer::renderModel($answer);
            if (empty(trim($speakable))) {
                return null;
            }

            $modelId = $this->models->getDefaultModel('TEXT2SOUND', $email->user->getId());
            $provider = $modelId ? $this->models->getProviderForModel($modelId) : null;
            $speech = $this->ai->synthesize($speakable, $language, $email->user->getId(), [
                'format' => 'mp3',
                'provider' => $provider ? strtolower($provider) : null,
            ]);
            $path = $this->files->uploaded($speech['relativePath']);

            $this->rateLimits->recordUsage($email->user, 'AUDIOS', [
                'provider' => $speech['provider'] ?? 'unknown',
                'model' => $speech['model'] ?? 'unknown',
                'model_id' => $speech['model_id'] ?? null,
                'source' => 'EMAIL',
                'media_usage' => ['characters' => $speech['text_length'] ?? mb_strlen($speakable)],
            ]);
        } catch (NoSpeakableTextException) {
            $this->logger->info('Email voice reply skipped: no speakable text');
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate TTS for email', ['error' => $e->getMessage()]);
            if ($email->reportsToDiscord()) {
                $this->discord->notifyEmailError('tts_failed', $email->fromEmail, $email->toEmail, $email->subject, $e->getMessage(), ['user_message' => $email->body]);
            }
        }

        return $path;
    }
}

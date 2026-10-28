<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\TtsTextSanitizer;
use App\Service\RateLimitService;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The spoken version of a reply (voice reply).
 *
 * The MP3 is stored on the reply and announced before `complete`, because
 * the client closes the stream on `complete`. When no audio can be stored —
 * over the audio limit, nothing speakable, a provider error — the reply
 * records why and the client gets one `voice_reply_failed` event (#2282).
 */
final readonly class VoiceReplyStep
{
    /** A media turn's answer is the generated file; it is not read aloud. */
    private const MEDIA_INTENTS = ['image_generation', 'video_generation', 'audio_generation', 'mediamaker'];

    public function __construct(
        private RateLimitService $rateLimitService,
        private AiFacade $aiFacade,
        private EntityManagerInterface $em,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private StreamMessageMeta $meta,
        private StreamUsageRecorder $usage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the turn is read aloud. A media turn is skipped on purpose and
     * silently. An empty or "0" answer still runs, so the sanitizer can
     * report `empty_text`.
     *
     * @param array<string, mixed> $classification
     */
    public function applies(StreamTurnRequest $turn, array $classification): bool
    {
        if (!$turn->options->voiceReply) {
            return false;
        }

        $handlerIntent = $classification['intent'] ?? $classification['topic'] ?? 'chat';
        if (in_array($handlerIntent, self::MEDIA_INTENTS, true)) {
            $this->logger->info('StreamController: Skipping voice reply for media generation', [
                'intent' => $handlerIntent,
            ]);

            return false;
        }

        return true;
    }

    public function run(SseWriter $sse, StreamCompleteEvent $event, Message $outgoing, User $user, string $responseText, string $language, bool $incognito): void
    {
        $limitCheck = $this->rateLimitService->checkLimit($user, 'AUDIOS');
        if (!$limitCheck['allowed']) {
            $this->logger->warning('StreamController: Voice reply skipped - rate limit exceeded', [
                'user_id' => $user->getId(),
            ]);
            $this->fail($sse, $outgoing, 'rate_limited', $incognito);

            return;
        }

        $speech = $this->synthesize($sse, $event, $outgoing, $user, $responseText, $language, $incognito);
        if (null !== $speech) {
            $this->recordUsage($event, $outgoing, $user, $speech);
        }
    }

    /**
     * @return array{url: string, provider: mixed, model: mixed, modelId: mixed, characters: mixed}|null null when the failure was already reported
     */
    private function synthesize(SseWriter $sse, StreamCompleteEvent $event, Message $outgoing, User $user, string $responseText, string $language, bool $incognito): ?array
    {
        try {
            $sse->send('tts_generating', ['language' => $language]);

            $ttsText = TtsTextSanitizer::prepareForSynthesis($responseText);
            if ('' === trim($ttsText)) {
                $this->fail($sse, $outgoing, 'empty_text', $incognito);

                return null;
            }

            // The facade sanitizes and truncates again and needs the answer language (#2283).
            $ttsResult = $this->aiFacade->synthesize($responseText, $language, $user->getId(), [
                'format' => 'mp3',
            ]);

            $audioUrl = '/api/v1/files/uploads/'.$ttsResult['relativePath'];
            $outgoing->setFile(1);
            $outgoing->setFilePath($audioUrl);
            $outgoing->setFileType('audio');

            // The TTS model, so the "Audio Model" badge names it live and
            // after a reload — not the chat model (#583).
            $ttsProvider = $ttsResult['provider'] ?? null;
            $ttsModelName = $ttsResult['model'] ?? null;
            $ttsModelId = $ttsResult['model_id'] ?? null;
            if (null !== $ttsProvider) {
                $outgoing->setMeta('ai_audio_provider', (string) $ttsProvider);
            }
            if (null !== $ttsModelName) {
                $outgoing->setMeta('ai_audio_model', (string) $ttsModelName);
            }
            if (null !== $ttsModelId && '' !== (string) $ttsModelId) {
                $outgoing->setMeta('ai_audio_model_id', (string) $ttsModelId);
            }

            // Incognito: synthesize() wrote the audio without a DB row; an
            // EPHEMERAL row lets the session cleanup and the reaper delete it.
            $ttsEphemeralFile = null;
            if (!$incognito) {
                $this->em->flush();
            } else {
                $ttsEphemeralFile = $this->generatedFileRegistrar->register(
                    $user->getId(),
                    $ttsResult['relativePath'],
                    'audio',
                    null,
                    $ttsProvider,
                    ephemeral: true,
                );
            }

            // The badges were built before TTS ran.
            $event->set('aiModels', $this->meta->buildAiModelsPayload($outgoing));

            $audioEvent = [
                'url' => $audioUrl,
                'provider' => $ttsProvider,
                'model' => $ttsModelName,
                'model_id' => $this->meta->normalizeModelId($ttsModelId, 'sse_audio_event'),
            ];
            if (null !== $ttsEphemeralFile?->getId()) {
                $audioEvent['file_id'] = $ttsEphemeralFile->getId();
            }
            $sse->send('audio', $audioEvent);

            return [
                'url' => $audioUrl,
                'provider' => $ttsProvider,
                'model' => $ttsModelName,
                'modelId' => $ttsModelId,
                'characters' => $ttsResult['text_length'] ?? mb_strlen($ttsText),
            ];
        } catch (NoSpeakableTextException) {
            $this->fail($sse, $outgoing, 'empty_text', $incognito);

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('StreamController: Voice reply TTS failed', [
                'error' => $e->getMessage(),
            ]);
            $this->fail($sse, $outgoing, 'provider_error', $incognito);

            return null;
        }
    }

    /**
     * The audio is stored and announced: an accounting failure must not
     * also report voice_reply_failed, or the client shows a player and a
     * failure sentence for the same turn.
     *
     * @param array{url: string, provider: mixed, model: mixed, modelId: mixed, characters: mixed} $speech
     */
    private function recordUsage(StreamCompleteEvent $event, Message $outgoing, User $user, array $speech): void
    {
        try {
            $recorded = \App\Service\Usage\LegacyUsageWriter::record($this->rateLimitService, $user, 'AUDIOS', [
                'provider' => $speech['provider'] ?? 'unknown',
                'model' => $speech['model'] ?? 'unknown',
                'model_id' => $speech['modelId'],
                'media_usage' => [
                    'characters' => $speech['characters'],
                ],
            ]);

            $event->addUsageExtra($this->usage->extraEntry(
                'TTS',
                null !== $speech['provider'] ? (string) $speech['provider'] : null,
                null !== $speech['model'] ? (string) $speech['model'] : null,
                $recorded,
            ));
            $outgoing->setMeta('ai_usage_extra', (string) json_encode($event->usageExtra()));
            $this->em->flush();

            $this->logger->info('StreamController: Voice reply generated', [
                'url' => $speech['url'],
                'provider' => $speech['provider'] ?? 'unknown',
                'model' => $speech['model'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('StreamController: Voice reply usage recording failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param 'provider_error'|'empty_text'|'rate_limited' $reason
     */
    private function fail(SseWriter $sse, Message $outgoing, string $reason, bool $incognito): void
    {
        $outgoing->setMeta('voice_reply_failed', $reason);
        if (!$incognito) {
            $this->em->flush();
        }

        $sse->send('voice_reply_failed', ['reason' => $reason]);
    }
}

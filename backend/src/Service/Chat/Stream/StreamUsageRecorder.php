<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Service\RateLimitService;
use App\Service\Usage\FacadeMeteredChat;
use App\Service\Usage\RecordedUsage;
use App\Service\Usage\UsageStatsService;
use App\Service\Usage\UsageTaximeterConfig;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records what a streamed turn cost and builds the usage taximeter entries.
 *
 * The chat call is recorded per reply. The auxiliary entries — sorting,
 * multi-task planning, transcription, media renders, TTS — feed the "models
 * used" list, so routing and media models appear with their real cost. The
 * charged cost is stored on the reply, so a reload rebuilds the session
 * totals (BUSELOG is never queried per chat).
 */
final readonly class StreamUsageRecorder
{
    private const MEDIA_ACTIONS = ['image' => 'IMAGES', 'video' => 'VIDEOS', 'audio' => 'AUDIOS'];

    public function __construct(
        private RateLimitService $rateLimitService,
        private UsageStatsService $usageStatsService,
        private UsageTaximeterConfig $usageTaximeterConfig,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata       handler-result metadata
     * @param array<string, mixed> $classification
     */
    public function recordStreamedReply(StreamTurnRequest $turn, StreamTurnState $state, Message $outgoing, array $metadata, array $classification, string $finalText, ?File $generatedFile): StreamUsage
    {
        $recorded = $this->rateLimitService->recordUsage($turn->caller->user, 'MESSAGES', FacadeMeteredChat::messages(
            $metadata,
            $turn->caller->source(),
            $state->messageText,
            $finalText,
            [
                'latency' => $metadata['latency'] ?? 0,
                'chat_id' => $state->chatId,
                'agentId' => $turn->options->agent?->agentId,
                'agentVersionId' => $turn->options->agent?->versionId,
            ],
        ));

        $chat = $this->chatPayload($outgoing, $recorded, $metadata);
        $extra = $this->contextUsage($classification, $metadata, $state->incoming());

        $mediaType = $metadata['media_type'] ?? null;
        $mediaUsage = $this->streamedMediaUsage($turn, $state, $metadata, $generatedFile);
        if ($mediaType && null !== $mediaUsage) {
            $extra[] = $this->extraEntry(strtoupper((string) $mediaType), self::label($metadata['provider'] ?? null), self::label($metadata['model'] ?? null), $mediaUsage);
        }

        $this->persistExtra($outgoing, $chat, $extra);

        return new StreamUsage($chat, $extra);
    }

    /**
     * The non-streaming twin: the media handler always recorded its render.
     *
     * @param array<string, mixed> $metadata       handler-result metadata
     * @param array<string, mixed> $classification
     */
    public function recordBufferedReply(StreamTurnRequest $turn, StreamTurnState $state, Message $outgoing, array $metadata, array $classification, string $content): StreamUsage
    {
        $incoming = $state->incoming();
        $recorded = $this->rateLimitService->recordUsage($turn->caller->user, 'MESSAGES', FacadeMeteredChat::messages(
            $metadata,
            $turn->caller->source(),
            $incoming->getText(),
            $content,
            [
                'latency' => $metadata['latency'] ?? 0,
                'chat_id' => $state->chat?->getId(),
                'agentId' => $turn->options->agent?->agentId,
                'agentVersionId' => $turn->options->agent?->versionId,
            ],
        ));

        $chat = $this->chatPayload($outgoing, $recorded, $metadata);
        $extra = $this->contextUsage($classification, $metadata, $incoming);

        $mediaUsage = ($metadata['media_recorded_usage'] ?? null) instanceof RecordedUsage ? $metadata['media_recorded_usage'] : null;
        if (null !== $mediaUsage && !empty($metadata['media_type'])) {
            $extra[] = $this->extraEntry(strtoupper((string) $metadata['media_type']), self::label($metadata['provider'] ?? null), self::label($metadata['model'] ?? null), $mediaUsage);
        }

        $this->persistExtra($outgoing, $chat, $extra);

        return new StreamUsage($chat, $extra);
    }

    /**
     * One auxiliary entry (sorting / media / TTS), shaped like the chat
     * `usage` payload.
     *
     * @return array<string, mixed>
     */
    public function extraEntry(string $kind, ?string $provider, ?string $model, RecordedUsage $recorded): array
    {
        return $recorded->toMessageUsage($provider, $model, $kind);
    }

    /**
     * Today's totals for the signed-in web user, or null when the display is
     * off — computed last, so every row of the turn is already written.
     *
     * @return array<string, mixed>|null
     */
    public function liveTotals(StreamTurnRequest $turn): ?array
    {
        if ($turn->caller->isWidget() || $turn->caller->isGuest() || !$this->usageTaximeterConfig->isEnabled()) {
            return null;
        }

        return $this->usageStatsService->getLiveTotals($turn->caller->user);
    }

    /**
     * The badge payload of the chat call, or null when there is nothing to
     * show (no tokens and no cost). The charged cost goes onto the reply so
     * MessageApiFormatter can rebuild the session after a reload.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|null
     */
    private function chatPayload(Message $message, RecordedUsage $recorded, array $metadata): ?array
    {
        $charged = FacadeMeteredChat::chargedCost($metadata);
        if (null !== $charged) {
            $message->setMeta('ai_chat_cost', $charged);
            FacadeMeteredChat::applyPriceKnown($message, $metadata);
        }

        if ($recorded->totalTokens <= 0 && (float) $recorded->chargedCost <= 0.0 && null === $charged) {
            return null;
        }

        if (null === $charged) {
            $recorded->attachChatCost($message);
        }

        $payload = $recorded->toMessageUsage(self::label($metadata['provider'] ?? null), self::label($metadata['model'] ?? null), 'LLM');
        $payload = self::withDisplayedTokens($payload, $metadata, $charged);
        if (null !== $charged) {
            $payload['cost'] = $charged;
        }
        if (array_key_exists('facade_price_known', $metadata)) {
            $payload['priceKnown'] = false !== $metadata['facade_price_known'];
        }

        return $payload;
    }

    /**
     * Token counts the person sees. A multitask plan keeps every node's
     * tokens on display_usage even when the turn no longer bills them.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function withDisplayedTokens(array $payload, array $metadata, ?string $charged): array
    {
        $usage = is_array($metadata['display_usage'] ?? null) && [] !== $metadata['display_usage']
            ? $metadata['display_usage']
            : null;
        if (null === $usage && null !== $charged && is_array($metadata['usage'] ?? null)) {
            $usage = $metadata['usage'];
        }
        if (!is_array($usage) || [] === $usage) {
            return $payload;
        }
        $payload['promptTokens'] = (int) ($usage['prompt_tokens'] ?? 0);
        $payload['completionTokens'] = (int) ($usage['completion_tokens'] ?? 0);
        $payload['totalTokens'] = (int) ($usage['total_tokens'] ?? ($payload['promptTokens'] + $payload['completionTokens']));

        return $payload;
    }

    /**
     * Sorting, then usage from before the answer itself: multi-task planning
     * from the handler metadata, transcription from the incoming message.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $metadata
     *
     * @return list<array<string, mixed>>
     */
    private function contextUsage(array $classification, array $metadata, Message $incoming): array
    {
        $extra = [];
        if (is_array($classification['sorting_usage'] ?? null)) {
            $extra[] = RecordedUsage::fromSortingUsage(
                $classification['sorting_usage'],
                isset($classification['sorting_provider']) ? (string) $classification['sorting_provider'] : null,
                isset($classification['sorting_model_name']) ? (string) $classification['sorting_model_name'] : null,
            );
        }

        $planningUsage = $metadata['planning_usage'] ?? null;
        if (is_array($planningUsage)) {
            $extra[] = $planningUsage;
        }

        $rawTranscriptionUsage = $incoming->getMeta('ai_transcription_usage');
        if (null !== $rawTranscriptionUsage && '' !== $rawTranscriptionUsage) {
            $transcriptionUsage = json_decode($rawTranscriptionUsage, true);
            if (is_array($transcriptionUsage)) {
                $extra[] = $transcriptionUsage;
            }
        }

        return $extra;
    }

    /**
     * A media render is recorded here only when the handler did not bill it
     * the moment the provider did (#1146: `usage_recorded`); recording it
     * twice would double-charge.
     *
     * @param array<string, mixed> $metadata
     */
    private function streamedMediaUsage(StreamTurnRequest $turn, StreamTurnState $state, array $metadata, ?File $generatedFile): ?RecordedUsage
    {
        $recorded = ($metadata['media_recorded_usage'] ?? null) instanceof RecordedUsage ? $metadata['media_recorded_usage'] : null;
        $mediaType = $metadata['media_type'] ?? null;
        if (!$mediaType || !empty($metadata['usage_recorded'])) {
            return $recorded;
        }

        $mediaAction = is_string($mediaType) ? (self::MEDIA_ACTIONS[$mediaType] ?? null) : null;
        if (null === $mediaAction) {
            return $recorded;
        }

        return $this->rateLimitService->recordUsage($turn->caller->user, $mediaAction, [
            'provider' => $metadata['provider'] ?? 'unknown',
            'model' => $metadata['model'] ?? 'unknown',
            'model_id' => $metadata['model_id'] ?? null,
            'chat_id' => $state->chatId,
            'source' => $turn->caller->source(),
            'response_bytes' => $generatedFile ? $generatedFile->getFileSize() : 0,
            'input_text' => $state->messageText,
            'media_usage' => $metadata['media_usage'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed>|null  $chat
     * @param list<array<string, mixed>> $extra
     */
    private function persistExtra(Message $outgoing, ?array $chat, array $extra): void
    {
        if ([] !== $extra) {
            $outgoing->setMeta('ai_usage_extra', (string) json_encode($extra));
        }
        if (null !== $chat || [] !== $extra) {
            $this->em->flush();
        }
    }

    private static function label(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}

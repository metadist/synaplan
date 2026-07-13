<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Records billable usage for an external speech-to-text (transcription) call.
 *
 * Before #1314 transcription was never cost-metered: the only BUSELOG row it
 * produced was a zero-cost FILE_ANALYSIS quota event, so every external Whisper
 * / Voxtral call was billed at $0 even though the provider charges per audio
 * second. `AiFacade::transcribe()` is the single choke point every external
 * (paid) transcription passes through — local whisper.cpp bypasses it and is
 * free — so recording here bills each channel (chat, upload, WhatsApp, widget,
 * video) exactly once with no duplication.
 *
 * The cost is recorded under its own `TRANSCRIPTION` action so it never inflates
 * the FILE_ANALYSIS / AUDIOS (TTS) quota counters; it still counts towards the
 * user's cost budget because `checkCostBudget()` sums BCOST across all actions.
 * Recording never throws — a billing hiccup must not break transcription.
 */
final readonly class TranscriptionUsageRecorder
{
    public const ACTION = 'TRANSCRIPTION';

    public function __construct(
        private RateLimitService $rateLimitService,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $extraMetadata Non-billing context (e.g. language) stored on the row
     */
    public function record(
        ?int $userId,
        ?int $modelId,
        string $provider,
        string $model,
        float $durationSeconds,
        array $extraMetadata = [],
    ): void {
        // No user, no priced model, or no measurable audio → nothing to bill.
        // (model_id is required because the per-second price lives on the model.)
        if (null === $userId || $userId <= 0 || null === $modelId || $durationSeconds <= 0.0) {
            return;
        }

        $user = $this->userRepository->find($userId);
        if (null === $user) {
            return;
        }

        try {
            $this->rateLimitService->recordUsage($user, self::ACTION, [
                'provider' => $provider,
                'model' => $model,
                'model_id' => $modelId,
                'media_usage' => ['duration_seconds' => $durationSeconds],
            ] + $extraMetadata);
        } catch (\Throwable $e) {
            $this->logger->warning('TranscriptionUsageRecorder: failed to record STT usage (non-fatal)', [
                'user_id' => $userId,
                'model_id' => $modelId,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

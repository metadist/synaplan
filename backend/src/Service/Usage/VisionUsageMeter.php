<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\AI\Pipeline\AiAction;
use App\AI\Vision\VisionReply;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Writes the usage row for one vision call made through
 * {@see \App\AI\Service\AiFacade::analyzeImage()}.
 *
 * Vision used to leave no row at all, so every picture a chat, a file upload,
 * a video frame or a chat-run lookup sent to a vision model was free. The row
 * has its own `VISION` action: it counts towards the cost budget (which sums
 * BCOST over every action) but never uses up a FILE_ANALYSIS slot.
 *
 * Under `AI.USAGE_HOOK = on` the usage hook already wrote the row and
 * {@see LegacyUsageWriter} hands back that receipt; under `shadow` the row is
 * written here and compared with the hook's preview. Recording never throws.
 */
final readonly class VisionUsageMeter
{
    public function __construct(
        private RateLimitService $rateLimitService,
        private UserRepository $users,
        private AiUsageSpecBuilder $specBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function record(?int $userId, string $provider, string $model, ?int $modelId, string $prompt, VisionReply $reply): ?RecordedUsage
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }

        $user = $this->users->find($userId);
        if (null === $user) {
            return null;
        }

        $modelId ??= $this->specBuilder->resolveModelId('vision', $provider, $model);

        try {
            return LegacyUsageWriter::record(
                $this->rateLimitService,
                $user,
                AiAction::Vision->value,
                AiUsageSpecBuilder::visionMetadata($provider, $model, $modelId, $prompt, $reply),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('VisionUsageMeter: failed to record vision usage (non-fatal)', [
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

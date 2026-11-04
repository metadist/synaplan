<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\User;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * One BUSELOG `TOOLS` row. The row carries no arguments and no result text.
 */
final readonly class ToolUsageRecorder
{
    public function __construct(
        private RateLimitService $rates,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function record(User $user, string $source, string $provider, string $model, string $input, bool $error): void
    {
        try {
            $this->rates->recordUsage($user, 'TOOLS', [
                'source' => $source,
                'provider' => $provider,
                'model' => $model,
                'input_text' => $input,
                'response_text' => $error ? 'error' : 'ok',
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'cached_tokens' => 0,
                    'cache_creation_tokens' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Tool usage row failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Summary;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Summarizes a document with Text Analytics, the account's ANALYZE model,
 * else CHAT (never the leftover hidden SUMMARIZE slot), else the provider
 * default: one model call with the caller's instructions, its usage recorded
 * under the caller's kind. The callers own what to summarize and how to
 * present it.
 */
final readonly class DocumentSummarizer
{
    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{summary: string, provider: ?string, model: ?string}
     */
    public function summarize(User $user, SummaryCall $call): array
    {
        $model = $this->model($user);
        $options = ['temperature' => $call->temperature];
        if ($model['provider']) {
            $options['provider'] = $model['provider'];
        }
        if ($model['model']) {
            $options['model'] = $model['model'];
        }

        $response = $this->aiFacade->chat([
            ['role' => 'system', 'content' => $call->instructions],
            ['role' => 'user', 'content' => $call->message],
        ], $user->getId(), $options);
        $summary = trim($response['content'] ?? '');

        $usage = [
            'provider' => $response['provider'] ?? 'unknown',
            'model' => $response['model'] ?? 'unknown',
            'model_id' => $model['model_id'],
            'usage' => $response['usage'] ?? [],
        ];
        if (null !== $call->startedAt) {
            $usage['latency'] = (int) ((microtime(true) - $call->startedAt) * 1000);
        }
        $this->rateLimitService->recordUsage($user, $call->usageKind, $usage + [
            'response_text' => $summary,
            'input_text' => $call->usageInput,
        ]);

        return ['summary' => $summary, 'provider' => $response['provider'] ?? null, 'model' => $response['model'] ?? null];
    }

    /**
     * @return array{model: ?string, provider: ?string, model_id: ?int}
     */
    private function model(User $user): array
    {
        try {
            return $this->modelConfigService->getSummaryModelConfig($user->getId());
        } catch (\Exception $e) {
            $this->logger->warning('Could not get Text Analytics model, will use provider default', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return ['model' => null, 'provider' => null, 'model_id' => null];
        }
    }
}

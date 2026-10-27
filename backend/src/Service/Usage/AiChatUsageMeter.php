<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Writes the usage row for one chat call made through {@see \App\AI\Service\AiFacade}.
 *
 * Callers used to remember this themselves. A path that forgot — a saved task,
 * a queued message, a summary, a research step — reached the provider and left
 * no row, so the provider bill had models the usage log did not. The facade
 * calls this for every chat. A caller that already folds the same tokens into
 * its own row passes `caller_meters` and this stays quiet (live voice is the
 * one that does: its session row already contains the answer).
 *
 * When the provider reports no tokens, the text of the call is handed to
 * {@see RateLimitService::recordUsage()} and the existing byte estimate runs.
 */
final readonly class AiChatUsageMeter
{
    public const ACTION = 'AI_CALL';

    public const CALLER_METERS = 'caller_meters';

    public function __construct(
        private RateLimitService $rateLimitService,
        private UserRepository $users,
        private ModelRepository $models,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $response provider, model, usage, content
     * @param list<mixed>          $messages
     * @param array<string, mixed> $options
     */
    public function record(?int $userId, array $response, array $messages, array $options): ?RecordedUsage
    {
        if (true === ($options[self::CALLER_METERS] ?? false)) {
            return null;
        }

        if (null === $userId || $userId <= 0) {
            $this->logger->warning('AiChatUsageMeter: chat call has no user, so it was not metered', [
                'provider' => $response['provider'] ?? null,
                'model' => $response['model'] ?? null,
            ]);

            return null;
        }

        $user = $this->users->find($userId);
        if (null === $user) {
            return null;
        }

        $provider = is_string($response['provider'] ?? null) ? $response['provider'] : '';
        $model = is_string($response['model'] ?? null) ? $response['model'] : '';
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $modelId = $this->modelId($provider, $model, $options);

        try {
            return $this->rateLimitService->recordUsage($user, self::ACTION, [
                'provider' => $provider,
                'model' => $model,
                'model_id' => $modelId,
                'usage' => $usage,
                'source' => 'ai_facade',
                'input_text' => self::textOf($messages),
                'response_text' => is_string($response['content'] ?? null) ? $response['content'] : '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AiChatUsageMeter: failed to record chat usage', [
                'user_id' => $userId,
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function modelId(string $provider, string $model, array $options): ?int
    {
        $given = $options['model_id'] ?? null;
        if (is_numeric($given) && (int) $given > 0) {
            return (int) $given;
        }

        if ('' === $provider || '' === $model) {
            return null;
        }

        return $this->models->findChatIdByProvider($provider, $model);
    }

    /**
     * @param list<mixed> $messages
     */
    private static function textOf(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (is_string($content) && '' !== $content) {
                $parts[] = $content;
            }
        }

        return implode("\n", $parts);
    }
}

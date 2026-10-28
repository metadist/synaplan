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

    /** Pulled off the provider options before the call. See {@see claim()}. */
    public const USAGE_SOURCE = 'usage_source';

    public const USAGE_ACTION = 'usage_action';

    public const USAGE_TURN_ID = 'usage_turn_id';

    public const USAGE_CHAT_ID = 'usage_chat_id';

    public const USAGE_AGENT_ID = 'usage_agent_id';

    public const USAGE_AGENT_VERSION_ID = 'usage_agent_version_id';

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
     * @param array<string, mixed> $context  attribution from {@see claim()}, already off the provider options
     */
    public function record(?int $userId, array $response, array $messages, array $options, array $context = []): ?RecordedUsage
    {
        if (true === ($options[self::CALLER_METERS] ?? false) || true === ($context[self::CALLER_METERS] ?? false)) {
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

        $metadata = [
            'provider' => $provider,
            'model' => $model,
            'model_id' => $modelId,
            'usage' => $usage,
            'source' => self::sourceOf($context),
            'input_text' => self::textOf($messages),
            'response_text' => is_string($response['content'] ?? null) ? $response['content'] : '',
        ];
        foreach (['chat_id', 'agentId', 'agentVersionId'] as $key) {
            if (isset($context[$key])) {
                $metadata[$key] = $context[$key];
            }
        }

        try {
            return LegacyUsageWriter::record($this->rateLimitService, $user, self::ACTION, $metadata);
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
     * Take chat, agent and channel off the options the provider is about to
     * receive. A provider that echoes unknown keys must not see them.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function claim(array &$options): array
    {
        $context = [];
        $source = $options[self::USAGE_SOURCE] ?? null;
        if (is_string($source) && '' !== trim($source)) {
            $context['source'] = trim($source);
        }
        unset($options[self::USAGE_SOURCE]);

        $action = $options[self::USAGE_ACTION] ?? null;
        if (is_string($action) && '' !== trim($action)) {
            $context['action'] = trim($action);
        }
        unset($options[self::USAGE_ACTION]);

        $turnId = $options[self::USAGE_TURN_ID] ?? null;
        if (is_string($turnId) && '' !== trim($turnId)) {
            $context['turn_id'] = trim($turnId);
        }
        unset($options[self::USAGE_TURN_ID]);

        foreach ([
            self::USAGE_CHAT_ID => 'chat_id',
            self::USAGE_AGENT_ID => 'agentId',
            self::USAGE_AGENT_VERSION_ID => 'agentVersionId',
        ] as $from => $to) {
            $value = $options[$from] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                $context[$to] = (int) $value;
            }
            unset($options[$from]);
        }

        if (true === ($options[self::CALLER_METERS] ?? false)) {
            $context[self::CALLER_METERS] = true;
        }
        unset($options[self::CALLER_METERS]);

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function sourceOf(array $context): string
    {
        $source = $context['source'] ?? null;

        return is_string($source) && '' !== trim($source) ? trim($source) : 'ai_facade';
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

<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\Service\AiFacade;
use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallParser;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * The model call of a chat run through {@see AiFacade}, on the provider and
 * model the turn chose, so usage is recorded and billed per call. A model
 * without native tool calls is asked without tools and simply answers.
 */
final readonly class FacadeChatRunModel implements ChatRunModel
{
    private const MICRO = 1_000_000;

    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
        private ToolCallingCapability $capability,
        private ToolCallParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    public function call(ChatRunState $state, array $messages, array $tools, ?string $toolChoice): ChatRunReply
    {
        $provider = $state->provider ?? $this->models->getDefaultProvider($state->userId, 'chat');
        $options = ['provider' => $provider, 'usage_turn_id' => 'chat-run-'.$state->runId];
        if (null !== $state->model) {
            $options['model'] = $state->model;
        }
        if ([] !== $tools && $this->capability->supports($provider, $state->model, false)) {
            $options['tools'] = $tools;
            $options['tool_choice'] = $toolChoice ?? 'auto';
        }
        try {
            $response = $this->ai->chat($messages, $state->userId, $options);
        } catch (\Throwable $e) {
            $this->logger->warning('The model call of a chat run failed', ['run_id' => $state->runId, 'provider' => $provider, 'model' => $state->model, 'exception' => $e]);

            throw new ChatRunModelFailed('The model call of a chat run failed.', 0, $e);
        }

        return new ChatRunReply(
            \is_string($response['content'] ?? null) ? $response['content'] : '',
            $this->calls($response['tool_calls'] ?? null),
            self::micro($response['facade_charged_cost'] ?? null),
        );
    }

    /**
     * @return list<array{id: string, name: string, args: array<string, mixed>, signature?: string}>
     */
    private function calls(mixed $wire): array
    {
        return ChatRunCalls::read($this->parser, $wire);
    }

    /** A charged dollar amount in micro units. */
    public static function micro(mixed $charged): int
    {
        return is_numeric($charged) ? max(0, (int) round((float) $charged * self::MICRO)) : 0;
    }
}

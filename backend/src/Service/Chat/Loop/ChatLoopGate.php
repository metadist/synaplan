<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Service\Message\Routing\RoutingHandoffResolver;
use App\Service\ModelConfigService;

/**
 * Whether one streamed web chat turn runs on the loop.
 *
 * Only a plain turn of a signed-in person in their own chat, whose chat
 * model calls tools while streaming, does. Incognito, guests, the widget,
 * agents, saved tasks, "Again", "Continue", turns with files, document
 * replies and a deferred routing decision keep today's path, as does every
 * turn while the loop is off. The answer does not change within a turn: the
 * auto-research gate, the search, the task planner and the chat handler all
 * ask, and all get the answer the first one got.
 */
final readonly class ChatLoopGate
{
    /** Set by the web chat stream on the options of a streamed turn. */
    public const OPTION = 'chat_loop_stream';

    private const EXCLUDING_OPTIONS = ['incognito', 'is_guest_mode', 'is_widget_mode', 'is_again', 'is_continuation', 'agentId', 'saved_task_id'];

    /** Its reply is a machine-readable file envelope, not a chat answer. */
    private const DOCUMENT_TOPIC = 'officemaker';

    /** @var \WeakMap<Message, bool> forgotten with the message, so a worker never reuses an answer */
    private \WeakMap $decided;

    public function __construct(
        private ChatLoopConfig $config,
        private ModelConfigService $models,
        private ToolCallingCapability $capability,
    ) {
        $this->decided = new \WeakMap();
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    public function forTurn(Message $message, array $options, array $classification): bool
    {
        return $this->decided[$message] ??= $this->decide($message, $options, $classification);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    private function decide(Message $message, array $options, array $classification): bool
    {
        if (true !== ($options[self::OPTION] ?? false) || $message->hasFiles() || 'IN' !== $message->getDirection()
            || $message->getChat()?->getUserId() !== $message->getUserId()
            || self::DOCUMENT_TOPIC === ($classification['topic'] ?? null) || RoutingHandoffResolver::isDeferred($classification)) {
            return false;
        }
        foreach (self::EXCLUDING_OPTIONS as $option) {
            if (!empty($options[$option])) {
                return false;
            }
        }
        if (!$this->config->isEnabled()) {
            return false;
        }
        $provider = $this->provider($message, $options);

        return null !== $provider && $this->capability->supports($provider, null, true);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function provider(Message $message, array $options): ?string
    {
        $modelId = $options['override_model_id'] ?? $options['model_id'] ?? null;
        if (!\is_int($modelId) || $modelId < 1) {
            $modelId = $this->models->getDefaultModel('CHAT', $this->models->getEffectiveUserIdForMessage($message));
        }

        return null === $modelId ? null : $this->models->getProviderForModel($modelId);
    }
}

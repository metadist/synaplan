<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\Agent;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Iam\Permission;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Pins a public widget turn to a published assistant, or falls back to the topic.
 */
final readonly class WidgetAgentRuntime
{
    /** One fallback warning per widget per hour, so a broken pin does not flood the log. */
    private const FALLBACK_WARNING_TTL_SECONDS = 3600;

    public function __construct(
        private AgentConfig $agentConfig,
        private AgentAccess $access,
        private AgentRepository $agents,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function apply(Widget $widget, User $owner, array $options): array
    {
        $options['fixed_task_prompt'] = $widget->getTaskPromptTopic();
        $agentId = $widget->getAgentId();
        if (null === $agentId || $agentId < 1 || !$this->agentConfig->isEnabled((int) $owner->getId())) {
            return $options;
        }

        try {
            $this->access->require($owner, $agentId, Permission::Use);
            $agent = $this->agents->find($agentId);
            if (!$agent instanceof Agent || $agent->isArchived() || !$agent->hasPublishedVersion()) {
                throw AgentNotAccessibleException::forId($agentId);
            }
            unset($options['fixed_task_prompt']);
            $options['agentId'] = $agentId;

            return $options;
        } catch (AgentNotAccessibleException) {
            $this->warnOnce($widget->getWidgetId(), $agentId);

            return $options;
        }
    }

    private function warnOnce(string $widgetId, int $agentId): void
    {
        $key = 'widget_agent_fallback_'.md5($widgetId);
        $this->cache->get($key, function (ItemInterface $item) use ($widgetId, $agentId): bool {
            $item->expiresAfter(self::FALLBACK_WARNING_TTL_SECONDS);
            $this->logger->warning('Widget assistant unavailable; falling back to topic', [
                'widget_id' => $widgetId,
                'agent_id' => $agentId,
            ]);

            return true;
        });
    }
}

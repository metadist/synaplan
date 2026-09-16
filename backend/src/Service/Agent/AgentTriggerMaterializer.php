<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\InboundEmailHandler;
use App\Entity\SavedTask;
use App\Entity\Widget;
use App\Repository\AgentVersionRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\WidgetRepository;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskService;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns published `triggers` into Saved Tasks and channel bindings.
 *
 * Schedules and mail rules become ordinary Saved Tasks keyed by
 * `BTRIGGERCONFIG.agentTrigger = {slug}:{id}`; removed or disabled entries
 * disable (never delete) their row. Conversational events (`widget`,
 * `whatsapp`, mail department) are written to their channel entity: an
 * enabled event binds, a disabled event unbinds. Bindings made from the
 * channel side that the definition does not mention are left untouched —
 * publishing a new version must never unbind a widget the owner attached
 * on the widget page.
 *
 * Runs after the publish transaction has committed, so one failing trigger
 * is logged and skipped instead of turning a successful publish into a 500.
 */
final readonly class AgentTriggerMaterializer
{
    private const KIND_MAIL = 'mail';
    private const KIND_WIDGET = 'widget';
    private const KIND_WHATSAPP = 'whatsapp';

    public function __construct(
        private AgentDefinitionValidator $validator,
        private AgentVersionRepository $versions,
        private SavedTaskService $savedTasks,
        private SavedTaskConfig $savedTaskConfig,
        private WidgetRepository $widgets,
        private InboundEmailHandlerRepository $mailboxes,
        private WhatsAppAgentBinding $whatsApp,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function sync(Agent $agent): void
    {
        $definition = $this->definition($agent);
        $triggers = $definition->toArray()['triggers'] ?? ['events' => [], 'schedules' => []];
        $events = is_array($triggers['events'] ?? null) ? $triggers['events'] : [];
        $schedules = is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [];

        $wanted = [];
        if ($this->savedTaskConfig->isEnabled($agent->getOwnerId())) {
            foreach ($schedules as $schedule) {
                if (!is_array($schedule)) {
                    continue;
                }
                $key = $this->key($agent, (string) ($schedule['id'] ?? ''));
                if ('' === $key) {
                    continue;
                }
                $wanted[$key] = true;
                $this->guarded($agent, $key, function () use ($agent, $key, $schedule): void {
                    if (!self::isEnabled($schedule)) {
                        $this->disableExisting($agent, $key);

                        return;
                    }
                    $this->upsertSchedule($agent, $key, $schedule);
                });
            }
            foreach ($events as $event) {
                if (!is_array($event) || self::KIND_MAIL !== ($event['kind'] ?? '') || !isset($event['rule'])) {
                    continue;
                }
                $key = $this->key($agent, (string) ($event['id'] ?? ''));
                if ('' === $key) {
                    continue;
                }
                $wanted[$key] = true;
                $this->guarded($agent, $key, function () use ($agent, $key, $event): void {
                    if (!self::isEnabled($event)) {
                        $this->disableExisting($agent, $key);

                        return;
                    }
                    $this->upsertMailRule($agent, $key, $event);
                });
            }
        }

        foreach ($this->savedTasks->listAgentTriggers($agent->getOwnerId(), $agent->getPromptId()) as $task) {
            $key = (string) ($task->getTriggerConfig()['agentTrigger'] ?? '');
            if ('' !== $key && !isset($wanted[$key]) && $task->isEnabled()) {
                $this->savedTasks->update($task, ['enabled' => false]);
            }
        }

        $this->guarded($agent, self::KIND_WIDGET, fn () => $this->syncWidgets($agent, $events));
        $this->guarded($agent, self::KIND_WHATSAPP, fn () => $this->syncWhatsApp($agent, $events));
        $this->guarded($agent, 'department', fn () => $this->syncDepartments($agent, $events));
    }

    public function disable(Agent $agent): void
    {
        foreach ($this->savedTasks->listAgentTriggers($agent->getOwnerId(), $agent->getPromptId()) as $task) {
            $this->savedTasks->update($task, ['enabled' => false]);
        }
    }

    private function definition(Agent $agent): AgentDefinition
    {
        $publishedId = $agent->getPublishedVersionId();
        if (null !== $publishedId) {
            $version = $this->versions->find($publishedId);
            if ($version instanceof AgentVersion) {
                return $this->validator->validate($version->getDefinition());
            }
        }

        return $this->validator->validate($agent->getDraft());
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function upsertSchedule(Agent $agent, string $key, array $schedule): void
    {
        $agentId = (int) $agent->getId();
        $this->savedTasks->upsertAgentTrigger(
            $agent->getOwnerId(),
            $agent->getPromptId(),
            (string) $schedule['name'],
            $key,
            [
                'enabled' => true,
                'allowUnattended' => (bool) ($schedule['allowUnattended'] ?? false),
                'triggerType' => SavedTask::TRIGGER_SCHEDULE,
                'triggerConfig' => [
                    'kind' => 'cron',
                    'expression' => (string) ($schedule['cron'] ?? ''),
                    'tz' => (string) ($schedule['tz'] ?? 'UTC'),
                    'agentId' => $agentId,
                ],
                'graph' => $this->chatGraph(SavedTask::TRIGGER_SCHEDULE, (string) ($schedule['instruction'] ?? '')),
            ],
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function upsertMailRule(Agent $agent, string $key, array $event): void
    {
        $mailbox = (string) ($event['mailbox'] ?? '');
        $parts = explode(':', $mailbox, 2);
        $accountId = isset($parts[1]) ? (int) $parts[1] : 0;
        $rule = is_array($event['rule'] ?? null) ? $event['rule'] : [];
        $name = 'Mail · '.$mailbox;
        $this->savedTasks->upsertAgentTrigger(
            $agent->getOwnerId(),
            $agent->getPromptId(),
            $name,
            $key,
            [
                'enabled' => true,
                'allowUnattended' => true,
                'triggerType' => SavedTask::TRIGGER_INBOUND_EMAIL,
                'triggerConfig' => [
                    'accountId' => $accountId,
                    'filter' => $rule,
                    'agentId' => (int) $agent->getId(),
                ],
                'graph' => $this->chatGraph(
                    SavedTask::TRIGGER_INBOUND_EMAIL,
                    is_string($event['instruction'] ?? null) ? (string) $event['instruction'] : '',
                ),
            ],
        );
    }

    /**
     * Each `widget` event binds or unbinds exactly the widget it names. A
     * disabled event only unbinds when the widget is still bound to *this*
     * agent — never steal a binding another assistant owns.
     *
     * @param list<mixed> $events
     */
    private function syncWidgets(Agent $agent, array $events): void
    {
        $agentId = (int) $agent->getId();
        foreach ($events as $event) {
            if (!is_array($event) || self::KIND_WIDGET !== ($event['kind'] ?? '')) {
                continue;
            }
            $widgetId = self::refId($event['widget'] ?? null);
            if ('' === $widgetId) {
                continue;
            }
            $widget = $this->widgets->findByWidgetId($widgetId);
            if (!$widget instanceof Widget || $widget->getOwnerId() !== $agent->getOwnerId()) {
                continue;
            }
            if (self::isEnabled($event)) {
                if ($widget->getAgentId() !== $agentId) {
                    $widget->setAgentId($agentId);
                    $this->widgets->save($widget, true);
                }
            } elseif ($widget->getAgentId() === $agentId) {
                $widget->setAgentId(null);
                $this->widgets->save($widget, true);
            }
        }
    }

    /**
     * The owner has one WhatsApp binding. An enabled `whatsapp` event claims
     * it; a disabled one releases it only if this agent currently holds it.
     * No `whatsapp` event at all leaves a channel-side binding alone.
     *
     * @param list<mixed> $events
     */
    private function syncWhatsApp(Agent $agent, array $events): void
    {
        $declared = false;
        $enabled = false;
        foreach ($events as $event) {
            if (!is_array($event) || self::KIND_WHATSAPP !== ($event['kind'] ?? '')) {
                continue;
            }
            $declared = true;
            if (self::isEnabled($event)) {
                $enabled = true;
                break;
            }
        }
        if (!$declared) {
            return;
        }
        $ownerId = $agent->getOwnerId();
        $agentId = (int) $agent->getId();
        if ($enabled) {
            if (!$this->whatsApp->isBoundTo($ownerId, $agentId)) {
                $this->whatsApp->set($ownerId, $agentId);
            }
        } elseif ($this->whatsApp->isBoundTo($ownerId, $agentId)) {
            $this->whatsApp->set($ownerId, null);
        }
    }

    /**
     * A `mail` event with a `department` pins that department of the named
     * mailbox to this agent; disabling it removes the pin only when it points
     * at this agent.
     *
     * @param list<mixed> $events
     */
    private function syncDepartments(Agent $agent, array $events): void
    {
        $agentId = (int) $agent->getId();
        foreach ($events as $event) {
            if (!is_array($event) || self::KIND_MAIL !== ($event['kind'] ?? '') || !isset($event['department'])) {
                continue;
            }
            $handlerId = (int) self::refId($event['mailbox'] ?? null);
            $handler = $handlerId > 0 ? $this->mailboxes->find($handlerId) : null;
            if (!$handler instanceof InboundEmailHandler || $handler->getUserId() !== $agent->getOwnerId()) {
                continue;
            }
            $target = (string) $event['department'];
            $departments = $handler->getDepartments();
            $changed = false;
            foreach ($departments as $i => $dept) {
                if (!is_array($dept) || !self::departmentMatches($dept, $target)) {
                    continue;
                }
                $bound = (int) ($dept['agentId'] ?? 0);
                if (self::isEnabled($event)) {
                    if ($bound !== $agentId) {
                        $departments[$i]['agentId'] = $agentId;
                        $changed = true;
                    }
                } elseif ($bound === $agentId) {
                    unset($departments[$i]['agentId']);
                    $changed = true;
                }
            }
            if ($changed) {
                $handler->setDepartments(array_values($departments));
                $this->em->persist($handler);
                $this->em->flush();
            }
        }
    }

    /**
     * @param array<string, mixed> $dept
     */
    private static function departmentMatches(array $dept, string $target): bool
    {
        $email = (string) ($dept['email'] ?? '');
        $name = (string) ($dept['name'] ?? '');

        return ('' !== $email && $email === $target) || ('' !== $name && $name === $target);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function isEnabled(array $entry): bool
    {
        return false !== ($entry['enabled'] ?? true);
    }

    /**
     * `widget:abc123` → `abc123`, `mailbox:7` → `7`. Anything else → ''.
     */
    private static function refId(mixed $ref): string
    {
        if (!is_string($ref)) {
            return '';
        }
        $pos = strpos($ref, ':');

        return false === $pos ? '' : substr($ref, $pos + 1);
    }

    /**
     * One trigger must not take the whole publish down: log and move on.
     *
     * @param callable(): void $work
     */
    private function guarded(Agent $agent, string $trigger, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error('Agent trigger materialization failed', [
                'agent_id' => $agent->getId(),
                'slug' => $agent->getSlug(),
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function disableExisting(Agent $agent, string $key): void
    {
        $task = $this->savedTasks->findAgentTrigger($agent->getOwnerId(), $agent->getPromptId(), $key);
        if ($task instanceof SavedTask) {
            $this->savedTasks->update($task, ['enabled' => false]);
        }
    }

    private function key(Agent $agent, string $id): string
    {
        return '' === $id ? '' : $agent->getSlug().':'.$id;
    }

    /**
     * @return array<string, mixed>
     */
    private function chatGraph(string $triggerType, string $instruction): array
    {
        return [
            'version' => 1,
            'trigger' => ['type' => $triggerType],
            'instruction' => $instruction,
            'nodes' => [
                ['id' => 'n1', 'capability' => 'chat', 'depends_on' => []],
            ],
        ];
    }
}

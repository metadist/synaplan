<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Entity\Widget;
use App\Repository\AgentVersionRepository;
use App\Repository\ConfigRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\WidgetRepository;
use App\Service\Agent\AgentTriggerMaterializer;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskService;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Channel bindings converge from both sides: a widget attached on the widget
 * page and one attached in the builder must end in the same state, and a
 * publish must never touch bindings the definition does not mention.
 */
final class AgentTriggerMaterializerTest extends TestCase
{
    private const OWNER = 4;
    private const AGENT = 7;
    private const OTHER_AGENT = 9;

    private WidgetRepository&MockObject $widgets;
    private ConfigRepository&MockObject $config;
    private SavedTaskService&MockObject $savedTasks;
    private LoggerInterface&MockObject $logger;
    private AgentTriggerMaterializer $materializer;

    protected function setUp(): void
    {
        $this->widgets = $this->createMock(WidgetRepository::class);
        $this->config = $this->createMock(ConfigRepository::class);
        $this->savedTasks = $this->createMock(SavedTaskService::class);
        $this->savedTasks->method('listAgentTriggers')->willReturn([]);
        $this->logger = $this->createMock(LoggerInterface::class);
        $savedTaskConfig = $this->createMock(SavedTaskConfig::class);
        $savedTaskConfig->method('isEnabled')->willReturn(false);

        $this->materializer = new AgentTriggerMaterializer(
            new AgentDefinitionValidator(),
            $this->createMock(AgentVersionRepository::class),
            $this->savedTasks,
            $savedTaskConfig,
            $this->widgets,
            $this->createMock(InboundEmailHandlerRepository::class),
            new WhatsAppAgentBinding($this->config),
            $this->createMock(EntityManagerInterface::class),
            $this->logger,
        );
    }

    public function testEnabledWidgetEventBindsTheWidget(): void
    {
        $widget = $this->widget('abc', null);
        $this->widgets->method('findByWidgetId')->willReturnMap([['abc', $widget]]);
        $this->widgets->expects(self::once())->method('save')->with($widget, true);

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':abc'],
        ]));

        self::assertSame(self::AGENT, $widget->getAgentId());
    }

    public function testAlreadyBoundWidgetIsNotRewritten(): void
    {
        $widget = $this->widget('abc', self::AGENT);
        $this->widgets->method('findByWidgetId')->willReturn($widget);
        $this->widgets->expects(self::never())->method('save');

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':abc'],
        ]));
    }

    public function testDisabledWidgetEventUnbindsOnlyThisAgent(): void
    {
        $mine = $this->widget('mine', self::AGENT);
        $theirs = $this->widget('theirs', self::OTHER_AGENT);
        $this->widgets->method('findByWidgetId')->willReturnMap([
            ['mine', $mine],
            ['theirs', $theirs],
        ]);
        $this->widgets->expects(self::once())->method('save')->with($mine, true);

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':mine', 'enabled' => false],
            ['id' => 'w2', 'kind' => 'widget', 'widget' => self::OWNER.':theirs', 'enabled' => false],
        ]));

        self::assertNull($mine->getAgentId());
        self::assertSame(self::OTHER_AGENT, $theirs->getAgentId());
    }

    public function testPublishWithoutWidgetEventsLeavesChannelSideBindingsAlone(): void
    {
        // A widget bound on the widget page is not part of the definition —
        // the old "unbind everything not listed" sweep must not run.
        $this->widgets->expects(self::never())->method('findBy');
        $this->widgets->expects(self::never())->method('save');

        $this->materializer->sync($this->agent([]));
    }

    public function testForeignWidgetIsIgnored(): void
    {
        $foreign = $this->widget('abc', null, ownerId: 99);
        $this->widgets->method('findByWidgetId')->willReturn($foreign);
        $this->widgets->expects(self::never())->method('save');

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':abc'],
        ]));

        self::assertNull($foreign->getAgentId());
    }

    public function testEnabledWhatsAppEventClaimsTheBinding(): void
    {
        $this->whatsAppBoundTo(null);
        $this->config->expects(self::once())->method('setValue')->with(self::OWNER, 'WHATSAPP', 'AGENTID', (string) self::AGENT);

        $this->materializer->sync($this->agent([
            ['id' => 'wa', 'kind' => 'whatsapp'],
        ]));
    }

    public function testDisabledWhatsAppEventReleasesOnlyOwnBinding(): void
    {
        $this->whatsAppBoundTo(self::AGENT);
        $this->config->expects(self::once())->method('setValue')->with(self::OWNER, 'WHATSAPP', 'AGENTID', '');

        $this->materializer->sync($this->agent([
            ['id' => 'wa', 'kind' => 'whatsapp', 'enabled' => false],
        ]));
    }

    public function testDisabledWhatsAppEventDoesNotReleaseAnotherAgentsBinding(): void
    {
        $this->whatsAppBoundTo(self::OTHER_AGENT);
        $this->config->expects(self::never())->method('setValue');

        $this->materializer->sync($this->agent([
            ['id' => 'wa', 'kind' => 'whatsapp', 'enabled' => false],
        ]));
    }

    public function testNoWhatsAppEventLeavesChannelSideBindingAlone(): void
    {
        $this->whatsAppBoundTo(self::OTHER_AGENT);
        $this->config->expects(self::never())->method('setValue');

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':abc'],
        ]));
    }

    public function testOneFailingTriggerIsLoggedAndTheRestStillRuns(): void
    {
        $this->widgets->method('findByWidgetId')->willThrowException(new \RuntimeException('db gone'));
        $this->logger->expects(self::once())->method('error')->with(
            'Agent trigger materialization failed',
            self::callback(static fn (array $ctx): bool => 'widget' === $ctx['trigger'] && 'db gone' === $ctx['error']),
        );
        $this->whatsAppBoundTo(null);
        $this->config->expects(self::once())->method('setValue')->with(self::OWNER, 'WHATSAPP', 'AGENTID', (string) self::AGENT);

        $this->materializer->sync($this->agent([
            ['id' => 'w1', 'kind' => 'widget', 'widget' => self::OWNER.':abc'],
            ['id' => 'wa', 'kind' => 'whatsapp'],
        ]));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function agent(array $events): Agent
    {
        $draft = AgentDefinition::defaults()->toArray();
        $draft['triggers'] = ['events' => $events, 'schedules' => []];
        $agent = new Agent(self::OWNER, 20, 'contract-review', 'Contract review', $draft);
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, self::AGENT);

        return $agent;
    }

    private function whatsAppBoundTo(?int $agentId): void
    {
        $this->config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use ($agentId): ?string {
                self::assertSame([self::OWNER, 'WHATSAPP', 'AGENTID'], [$ownerId, $group, $setting]);

                return null === $agentId ? null : (string) $agentId;
            }
        );
    }

    private function widget(string $widgetId, ?int $agentId, int $ownerId = self::OWNER): Widget
    {
        $widget = new Widget();
        $widget->setOwnerId($ownerId);
        $widget->setWidgetId($widgetId);
        $widget->setAgentId($agentId);

        return $widget;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\Agent;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentAccess;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Iam\Permission;
use App\Service\Widget\WidgetAgentRuntime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class WidgetAgentRuntimeTest extends TestCase
{
    public function testFlagOffKeepsTopicPath(): void
    {
        $widget = $this->widget(9);
        $owner = $this->user(4);
        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $runtime = new WidgetAgentRuntime(
            $config,
            $this->createMock(AgentAccess::class),
            $this->createMock(AgentRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(CacheInterface::class),
        );

        $out = $runtime->apply($widget, $owner, ['channel' => 'WIDGET']);

        self::assertSame('tools:widget-default', $out['fixed_task_prompt']);
        self::assertArrayNotHasKey('agentId', $out);
    }

    public function testBoundAssistantReplacesTopic(): void
    {
        $widget = $this->widget(9);
        $owner = $this->user(4);
        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $access = $this->createMock(AgentAccess::class);
        $access->expects(self::once())->method('require')->with($owner, 9, Permission::Use);
        $agent = $this->createMock(Agent::class);
        $agent->method('isArchived')->willReturn(false);
        $agent->method('hasPublishedVersion')->willReturn(true);
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('find')->with(9)->willReturn($agent);
        $runtime = new WidgetAgentRuntime(
            $config,
            $access,
            $agents,
            $this->createMock(LoggerInterface::class),
            $this->createMock(CacheInterface::class),
        );

        $out = $runtime->apply($widget, $owner, ['fixed_task_prompt' => 'old', 'channel' => 'WIDGET']);

        self::assertSame(9, $out['agentId']);
        self::assertArrayNotHasKey('fixed_task_prompt', $out);
    }

    public function testLostAccessFallsBackToTopic(): void
    {
        $widget = $this->widget(9);
        $owner = $this->user(4);
        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $access = $this->createMock(AgentAccess::class);
        $access->method('require')->willThrowException(AgentNotAccessibleException::forId(9));
        $cache = $this->createMock(CacheInterface::class);
        $item = $this->createMock(\Symfony\Contracts\Cache\ItemInterface::class);
        $cache->method('get')->willReturnCallback(static function (string $_key, callable $cb) use ($item): mixed {
            return $cb($item);
        });
        $runtime = new WidgetAgentRuntime(
            $config,
            $access,
            $this->createMock(AgentRepository::class),
            $this->createMock(LoggerInterface::class),
            $cache,
        );

        $out = $runtime->apply($widget, $owner, ['channel' => 'WIDGET']);

        self::assertSame('tools:widget-default', $out['fixed_task_prompt']);
        self::assertArrayNotHasKey('agentId', $out);
    }

    private function widget(int $agentId): Widget
    {
        $widget = new Widget();
        $widget->setTaskPromptTopic('tools:widget-default');
        $widget->setAgentId($agentId);

        return $widget;
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}

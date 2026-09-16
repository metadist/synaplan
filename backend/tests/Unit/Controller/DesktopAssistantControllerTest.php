<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DesktopAssistantController;
use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\DesktopAssistantLister;
use PHPUnit\Framework\TestCase;

final class DesktopAssistantControllerTest extends TestCase
{
    public function testListRequiresAuth(): void
    {
        $controller = new DesktopAssistantController(
            $this->createMock(AgentConfig::class),
            $this->createMock(DesktopAssistantLister::class),
        );

        $response = $controller->list(null);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListReturns404WhenAgentsDisabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->with(4)->willReturn(false);

        $lister = $this->createMock(DesktopAssistantLister::class);
        $lister->expects($this->never())->method('listRunnable');

        $controller = new DesktopAssistantController($config, $lister);
        $response = $controller->list($user);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(DesktopAssistantController::DISABLED_CODE, $data['error']['code']);
    }

    public function testListReturnsPublicViewsWhenEnabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $row = ['id' => 1, 'name' => 'Review', 'models' => ['chat' => 'ollama:llama3.2:chat', 'vision' => null, 'vectorize' => null]];
        $lister = $this->createMock(DesktopAssistantLister::class);
        $lister->expects($this->once())->method('listRunnable')->with($user)->willReturn([$row]);

        $controller = new DesktopAssistantController($config, $lister);
        $response = $controller->list($user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('list', $data['object']);
        $this->assertSame([$row], $data['data']);
    }

    public function testGetReturns404WhenAgentsDisabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(false);

        $lister = $this->createMock(DesktopAssistantLister::class);
        $lister->expects($this->never())->method('oneRunnable');

        $controller = new DesktopAssistantController($config, $lister);
        $response = $controller->get(12, $user);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(DesktopAssistantController::DISABLED_CODE, $data['error']['code']);
    }

    public function testGetReturnsPublicViewWhenEnabled(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $row = ['id' => 12, 'name' => 'Review', 'models' => ['chat' => 'ollama:llama3.2:chat', 'vision' => null, 'vectorize' => null]];
        $lister = $this->createMock(DesktopAssistantLister::class);
        $lister->expects($this->once())->method('oneRunnable')->with($user, 12)->willReturn($row);

        $controller = new DesktopAssistantController($config, $lister);
        $response = $controller->get(12, $user);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame($row, $data);
    }
}

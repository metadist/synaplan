<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ComputeWorkspaceController;
use App\Entity\User;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeWorkspaceService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class ComputeWorkspaceControllerTest extends TestCase
{
    public function testOtherUsersWorkspace404(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('workspacesEnabled')->willReturn(true);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->expects($this->once())
            ->method('downloadFile')
            ->willThrowException(new ComputeRefusedException('workspace_not_found', 'There is no file-work folder yet.'));

        $controller = $this->controller($config, $workspaces);
        $other = $this->createStub(User::class);
        $other->method('getId')->willReturn(99);

        $response = $controller->download('secret.csv', $other);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testNoImplicitIngest(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('workspacesEnabled')->willReturn(true);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->expects($this->once())->method('listFiles')->willReturn([]);

        $controller = $this->controller($config, $workspaces);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $request = new \Symfony\Component\HttpFoundation\Request();

        $response = $controller->files($request, $user);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(['files' => []], $payload);
        $params = (new \ReflectionMethod(ComputeWorkspaceService::class, '__construct'))->getParameters();
        $types = array_map(static fn (\ReflectionParameter $p): string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : '', $params);
        self::assertNotContains(\App\Repository\FileRepository::class, $types);
    }

    public function testFlagOffIs404(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('workspacesEnabled')->willReturn(false);
        $workspaces = $this->createMock(ComputeWorkspaceService::class);
        $workspaces->expects($this->never())->method('forUser');

        $controller = $this->controller($config, $workspaces);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $response = $controller->show($user);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function controller(ComputeConfig $config, ComputeWorkspaceService $workspaces): ComputeWorkspaceController
    {
        $controller = new ComputeWorkspaceController($config, $workspaces);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }
}

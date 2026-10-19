<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\FileRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Chat\ConnectedAppProbe;
use App\Service\Chat\GettingStartedStatusService;
use App\Service\FreeApi\FreeApiState;
use PHPUnit\Framework\TestCase;

final class GettingStartedStatusServiceTest extends TestCase
{
    public function testANonAdminOmitsTheProviderAndASwitchedOffAssistant(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('existsForUser')->willReturn(true);
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::never())->method('existsForOwner');
        $agentConfig = $this->createMock(AgentConfig::class);
        $agentConfig->method('isEnabled')->with(7)->willReturn(false);
        $readiness = $this->createMock(ChatReadinessService::class);
        $readiness->expects(self::never())->method('isChatReady');
        $apps = $this->createMock(ConnectedAppProbe::class);
        $apps->method('isAnyConnected')->willReturn(false);

        $items = $this->service($files, $agents, $agentConfig, $readiness, $apps)
            ->items($this->user(7, false));

        self::assertSame(['file' => true, 'app' => false], $items);
        self::assertArrayNotHasKey('provider', $items);
        self::assertArrayNotHasKey('assistant', $items);
    }

    public function testAnAdminGetsTheProviderAndARealAssistantCount(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('existsForUser')->willReturn(false);
        $agents = $this->createMock(AgentRepository::class);
        $agents->method('existsForOwner')->willReturn(true);
        $agentConfig = $this->createMock(AgentConfig::class);
        $agentConfig->method('isEnabled')->willReturn(true);
        $readiness = $this->createMock(ChatReadinessService::class);
        $readiness->method('isChatReady')->willReturn(false);
        $apps = $this->createMock(ConnectedAppProbe::class);
        $apps->method('isAnyConnected')->willReturn(true);

        $items = $this->service($files, $agents, $agentConfig, $readiness, $apps)
            ->items($this->user(3, true));

        self::assertSame([
            'file' => false,
            'assistant' => true,
            'app' => true,
            'provider' => false,
        ], $items);
    }

    public function testAFailedCheckHidesThatStep(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('existsForUser')->willThrowException(new \RuntimeException('down'));
        $agents = $this->createMock(AgentRepository::class);
        $agentConfig = $this->createMock(AgentConfig::class);
        $agentConfig->method('isEnabled')->willThrowException(new \RuntimeException('down'));
        $readiness = $this->createMock(ChatReadinessService::class);
        $readiness->method('isChatReady')->willThrowException(new \RuntimeException('down'));
        $apps = $this->createMock(ConnectedAppProbe::class);
        $apps->method('isAnyConnected')->willThrowException(new \RuntimeException('down'));

        $items = $this->service($files, $agents, $agentConfig, $readiness, $apps)
            ->items($this->user(7, true));

        self::assertSame([], $items);
    }

    public function testTheProviderStepStaysOpenWhileTheDemoAnswersChat(): void
    {
        $readiness = $this->createStub(ChatReadinessService::class);
        $readiness->method('isChatReady')->willReturn(true);
        $demo = $this->createStub(FreeApiState::class);
        $demo->method('isActive')->willReturn(true);
        $agentConfig = $this->createStub(AgentConfig::class);
        $agentConfig->method('isEnabled')->willReturn(false);

        $items = $this->service(
            $this->createStub(FileRepository::class),
            $this->createStub(AgentRepository::class),
            $agentConfig,
            $readiness,
            $this->createStub(ConnectedAppProbe::class),
            $demo,
        )->items($this->user(3, true));

        self::assertFalse($items['provider']);
    }

    private function service(
        FileRepository $files,
        AgentRepository $agents,
        AgentConfig $agentConfig,
        ChatReadinessService $readiness,
        ConnectedAppProbe $apps,
        ?FreeApiState $demo = null,
    ): GettingStartedStatusService {
        return new GettingStartedStatusService($files, $agents, $agentConfig, $readiness, $apps, $demo);
    }

    private function user(int $id, bool $admin): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($admin);

        return $user;
    }
}

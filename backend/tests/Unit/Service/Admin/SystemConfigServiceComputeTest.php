<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\AI\Credential\ProviderKeyStore;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfigService;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\Contract\ComputeHealth;
use App\Service\EncryptionService;
use App\Service\GuestChatConfig;
use App\Service\RegistrationConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SystemConfigServiceComputeTest extends TestCase
{
    public function testRefusesAboveComputeCaps(): void
    {
        $health = ComputeHealth::fromJson((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/compute-contract/health.json',
        ));
        $client = $this->createStub(ComputeClient::class);
        $client->method('health')->willReturn($health);

        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->never())->method('setValue');
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $service = new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $repo,
            defaultTtsUrl: 'http://localhost:10200',
            providerKeyStore: new ProviderKeyStore($repo, $encryption, new NullLogger()),
            encryption: $encryption,
            registrationConfig: new RegistrationConfig($repo),
            guestChatConfig: new GuestChatConfig($repo),
            computeClient: $client,
        );

        $result = $service->setValue('COMPUTE_DEFAULT_TIMEOUT_SEC', '999');

        self::assertFalse($result['success']);
        self::assertStringContainsString('300', (string) ($result['message'] ?? ''));
        self::assertStringContainsString('seconds', (string) ($result['message'] ?? ''));
    }

    public function testAcceptsValueAtTheCap(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->once())->method('setValue');

        $result = $this->service($repo)->setValue('COMPUTE_DEFAULT_TIMEOUT_SEC', '300');

        self::assertTrue($result['success']);
    }

    /**
     * The fixture sidecar reports features.egress=false (as the shipped sidecar
     * does): switching website access on must be refused with a plain sentence
     * instead of storing a flag every run would then contradict.
     */
    public function testRefusesEgressFlagTheSidecarDoesNotOffer(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->never())->method('setValue');

        $result = $this->service($repo)->setValue('COMPUTE_EGRESS_ENABLED', 'true');

        self::assertFalse($result['success']);
        self::assertStringContainsString('Website access', (string) ($result['message'] ?? ''));
        self::assertStringContainsString('stays off', (string) ($result['message'] ?? ''));
    }

    public function testSwitchingEgressOffIsAlwaysAllowed(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->once())->method('setValue');

        self::assertTrue($this->service($repo)->setValue('COMPUTE_EGRESS_ENABLED', 'false')['success']);
    }

    public function testAcceptsWorkspacesFlagTheSidecarOffers(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->once())->method('setValue');

        self::assertTrue($this->service($repo)->setValue('COMPUTE_WORKSPACES_ENABLED', 'true')['success']);
    }

    public function testRefusesChildFlagWhenTheSidecarHealthCheckFails(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->never())->method('setValue');
        $client = $this->createStub(ComputeClient::class);
        $client->method('health')->willThrowException(new \RuntimeException('sidecar down'));
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $service = new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $repo,
            defaultTtsUrl: 'http://localhost:10200',
            providerKeyStore: new ProviderKeyStore($repo, $encryption, new NullLogger()),
            encryption: $encryption,
            registrationConfig: new RegistrationConfig($repo),
            guestChatConfig: new GuestChatConfig($repo),
            computeClient: $client,
        );

        $result = $service->setValue('COMPUTE_WORKSPACES_ENABLED', 'true');

        self::assertFalse($result['success']);
        self::assertStringContainsString('not reachable', (string) ($result['message'] ?? ''));
    }

    private function service(ConfigRepository $repo): SystemConfigService
    {
        $health = ComputeHealth::fromJson((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/compute-contract/health.json',
        ));
        $client = $this->createStub(ComputeClient::class);
        $client->method('health')->willReturn($health);
        $encryption = new EncryptionService('test-secret', new NullLogger());

        return new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $repo,
            defaultTtsUrl: 'http://localhost:10200',
            providerKeyStore: new ProviderKeyStore($repo, $encryption, new NullLogger()),
            encryption: $encryption,
            registrationConfig: new RegistrationConfig($repo),
            guestChatConfig: new GuestChatConfig($repo),
            computeClient: $client,
        );
    }
}

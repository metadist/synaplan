<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\AI\Service\ProviderModelListing;
use App\Command\DiscoverModelsCommand;
use App\Repository\UserRepository;
use App\Service\DiscordNotificationService;
use App\Service\ModelDiscovery\ModelDiscoveryReport;
use App\Service\ModelDiscovery\ModelDiscoveryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscoverModelsCommandTest extends TestCase
{
    /** @var list<string> */
    private array $webhookBodies = [];

    private int $webhookStatus = 204;

    public function testDisabledExitsZeroWithoutRunningDiscovery(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(false);
        $discovery->expects($this->never())->method('run');

        $tester = $this->tester($discovery);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('MODEL_DISCOVERY_ENABLED=false', $tester->getDisplay());
        $this->assertSame([], $this->webhookBodies);
    }

    public function testSuccessWithPendingDoesNotFail(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [[
                'provider' => 'openai',
                'status' => ProviderModelListing::STATUS_OK,
                'detail' => null,
                'listedCount' => 2,
                'pendingCount' => 1,
            ]],
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 4,
            ]],
            failedProviders: [],
            baselinesRecorded: [],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markBaselinesAnnounced')->with([]);

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('gpt-brand-new', $tester->getDisplay());
        $this->assertStringContainsString('Discord alert sent', $tester->getDisplay());
        $this->assertCount(1, $this->webhookBodies);
        $body = json_decode($this->webhookBodies[0], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('🆕 New AI models detected', $body['embeds'][0]['title']);
        $this->assertStringContainsString('gpt-brand-new', json_encode($body, \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('ModelCatalog', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testWebhook404ReleasesClaimAndDoesNotMarkBaselines(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            failedProviders: [],
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 12],
            ],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('releaseNotifyDay');
        $discovery->expects($this->never())->method('markBaselinesAnnounced');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook', webhookStatus: 404);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord post failed', $tester->getDisplay());
        $this->assertStringContainsString('unclaimed', $tester->getDisplay());
        $this->assertStringNotContainsString('Discord alert sent', $tester->getDisplay());
        $this->assertCount(1, $this->webhookBodies);
    }

    public function testWebhook204MarksBaselinesAnnounced(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            failedProviders: [],
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 12],
                ['provider' => 'anthropic', 'idCount' => 8],
            ],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markBaselinesAnnounced')
            ->with(['openai', 'anthropic']);
        $discovery->expects($this->never())->method('releaseNotifyDay');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook', webhookStatus: 204);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord alert sent', $tester->getDisplay());
    }

    public function testNotifyWithoutWebhookMarksBaselinesAnnounced(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            failedProviders: [],
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 3],
            ],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->expects($this->never())->method('claimNotifyDay');
        $discovery->expects($this->once())->method('markBaselinesAnnounced')->with(['openai']);

        $tester = $this->tester($discovery, webhookUrl: null);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord notifications are disabled', $tester->getDisplay());
        $this->assertSame([], $this->webhookBodies);
    }

    public function testSecondNotifySameDayDoesNotPost(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [],
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
            ]],
            failedProviders: [],
            baselinesRecorded: [],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->method('claimNotifyDay')->willReturn(false);
        $discovery->expects($this->never())->method('markBaselinesAnnounced');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already claimed', $tester->getDisplay());
        $this->assertSame([], $this->webhookBodies);
    }

    public function testExceptionPostsFailureOnceAndReturnsOne(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willThrowException(new \RuntimeException('boom'));
        $discovery->method('claimNotifyDay')->willReturn(true);

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('could not run', $tester->getDisplay());
        $this->assertCount(1, $this->webhookBodies);
        $body = json_decode($this->webhookBodies[0], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('⚠️ New-model check could not run', $body['embeds'][0]['title']);
        $this->assertStringContainsString('boom', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testBaselineOnlyNotifyPayload(): void
    {
        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn(new ModelDiscoveryReport(
            providers: [],
            pending: [],
            failedProviders: [],
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 12],
                ['provider' => 'anthropic', 'idCount' => 8],
            ],
            obsoleteIgnores: [],
            shouldNotify: true,
        ));
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markBaselinesAnnounced')
            ->with(['openai', 'anthropic']);

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $body = json_decode($this->webhookBodies[0], true, 512, \JSON_THROW_ON_ERROR);
        $encoded = json_encode($body, \JSON_THROW_ON_ERROR);
        $this->assertSame('✅ New-model check is active', $body['embeds'][0]['title']);
        $this->assertStringContainsString('Baseline recorded for openai, anthropic: 20 ids', $encoded);
        $this->assertStringContainsString('from tomorrow', $encoded);
        $this->assertStringNotContainsString('Action required', $encoded);
    }

    private function tester(
        ModelDiscoveryService $discovery,
        ?string $webhookUrl = null,
        int $webhookStatus = 204,
    ): CommandTester {
        $this->webhookBodies = [];
        $this->webhookStatus = $webhookStatus;
        $http = new MockHttpClient(function (string $method, string $url, array $options = []): MockResponse {
            $this->webhookBodies[] = (string) ($options['body'] ?? json_encode($options['json'] ?? [], \JSON_THROW_ON_ERROR));

            return new MockResponse('', ['http_code' => $this->webhookStatus]);
        });

        $discord = new DiscordNotificationService(
            $http,
            new NullLogger(),
            $this->createStub(UserRepository::class),
            $webhookUrl,
        );

        $command = new DiscoverModelsCommand($discovery, $discord);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('app:models:discover'));
    }
}

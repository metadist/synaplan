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
        $report = $this->report(
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 4,
                'label' => 'New family',
            ]],
            newPending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 4,
                'label' => 'New family',
            ]],
            providers: [[
                'provider' => 'openai',
                'status' => ProviderModelListing::STATUS_OK,
                'detail' => null,
                'listedCount' => 2,
                'pendingCount' => 1,
                'silencedByClass' => 0,
            ]],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markDiscoveriesAnnounced')->with($report);

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('gpt-brand-new', $tester->getDisplay());
        $this->assertStringContainsString('New family', $tester->getDisplay());
        $this->assertStringContainsString('Discord alert sent', $tester->getDisplay());
        $this->assertCount(1, $this->webhookBodies);
        $body = json_decode($this->webhookBodies[0], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('🆕 New AI models detected', $body['embeds'][0]['title']);
        $this->assertStringContainsString('gpt-brand-new', json_encode($body, \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('ModelCatalog', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function testWebhook404ReleasesClaimAndDoesNotMarkAnnounced(): void
    {
        $report = $this->report(
            baselinesRecorded: [['provider' => 'openai', 'idCount' => 12]],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('releaseNotifyDay');
        $discovery->expects($this->never())->method('markDiscoveriesAnnounced');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook', webhookStatus: 404);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord post failed', $tester->getDisplay());
        $this->assertStringContainsString('unclaimed', $tester->getDisplay());
        $this->assertStringNotContainsString('Discord alert sent', $tester->getDisplay());
        $this->assertCount(1, $this->webhookBodies);
    }

    public function testWebhook204MarksDiscoveriesAnnounced(): void
    {
        $report = $this->report(
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 12],
                ['provider' => 'anthropic', 'idCount' => 8],
            ],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markDiscoveriesAnnounced')->with($report);
        $discovery->expects($this->never())->method('releaseNotifyDay');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook', webhookStatus: 204);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord alert sent', $tester->getDisplay());
    }

    public function testNotifyWithoutWebhookMarksAnnounced(): void
    {
        $report = $this->report(
            baselinesRecorded: [['provider' => 'openai', 'idCount' => 3]],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->expects($this->never())->method('claimNotifyDay');
        $discovery->expects($this->once())->method('markDiscoveriesAnnounced')->with($report);

        $tester = $this->tester($discovery, webhookUrl: null);
        $tester->execute(['--notify' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Discord notifications are disabled', $tester->getDisplay());
        $this->assertSame([], $this->webhookBodies);
    }

    public function testRunWithoutNotifyMarksNothing(): void
    {
        $report = $this->report(
            newPending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ]],
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ]],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->expects($this->never())->method('markDiscoveriesAnnounced');
        $discovery->expects($this->never())->method('claimNotifyDay');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame([], $this->webhookBodies);
    }

    public function testSecondNotifySameDayDoesNotPost(): void
    {
        $report = $this->report(
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ]],
            newPending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-20',
                'daysPending' => 1,
                'label' => 'New family',
            ]],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(false);
        $discovery->expects($this->never())->method('markDiscoveriesAnnounced');

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
        $report = $this->report(
            baselinesRecorded: [
                ['provider' => 'openai', 'idCount' => 12],
                ['provider' => 'anthropic', 'idCount' => 8],
            ],
            shouldNotify: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markDiscoveriesAnnounced')->with($report);

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

    public function testMondayReminderPostsOpenItems(): void
    {
        $report = $this->report(
            openPending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-10',
                'daysPending' => 11,
                'label' => 'New family',
            ]],
            pending: [[
                'provider' => 'openai',
                'id' => 'gpt-brand-new',
                'firstSeen' => '2026-09-10',
                'daysPending' => 11,
                'label' => 'New family',
            ]],
            shouldNotify: true,
            isMondayReminder: true,
        );

        $discovery = $this->createMock(ModelDiscoveryService::class);
        $discovery->method('isEnabled')->willReturn(true);
        $discovery->method('run')->willReturn($report);
        $discovery->method('claimNotifyDay')->willReturn(true);
        $discovery->expects($this->once())->method('markDiscoveriesAnnounced');

        $tester = $this->tester($discovery, webhookUrl: 'https://discord.example/hook');
        $tester->execute(['--notify' => true]);

        $body = json_decode($this->webhookBodies[0], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('🔁 Weekly reminder: models still open', $body['embeds'][0]['title']);
        $this->assertStringContainsString('Still open', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{provider: string, status: string, detail: string|null, listedCount: int, pendingCount: int, silencedByClass: int}> $providers
     * @param list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>                                 $pending
     * @param list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>                                 $newPending
     * @param list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>                                 $openPending
     * @param list<array{provider: string, detail: string, failingSince: string, isNew: bool}>                                              $failedProviders
     * @param list<array{provider: string, idCount: int}>                                                                                   $baselinesRecorded
     */
    private function report(
        array $providers = [],
        array $pending = [],
        array $newPending = [],
        array $openPending = [],
        array $failedProviders = [],
        array $baselinesRecorded = [],
        bool $shouldNotify = false,
        bool $isMondayReminder = false,
    ): ModelDiscoveryReport {
        return new ModelDiscoveryReport(
            providers: $providers,
            pending: $pending,
            newPending: $newPending,
            openPending: $openPending,
            failedProviders: $failedProviders,
            baselinesRecorded: $baselinesRecorded,
            obsoleteIgnores: [],
            silencedByClass: [],
            shouldNotify: $shouldNotify,
            isMondayReminder: $isMondayReminder,
        );
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

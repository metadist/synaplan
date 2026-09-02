<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\BillingService;
use App\Service\Capability\CapabilityService;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\MailerConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Plugin\PluginManager;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\Search\BraveSearchService;
use App\Service\SelfAware\CapabilityState;
use App\Service\SelfAware\PlatformCapabilityInventory;
use App\Service\Update\UpdateStatusService;
use PHPUnit\Framework\TestCase;

final class PlatformCapabilityInventoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        parent::tearDown();
    }

    public function testNoKeysInstallMarksChatAndSearchAsNeedsSetup(): void
    {
        $this->setEnv('QDRANT_URL', '');
        $this->setEnv('OFFICE_CONVERT_URL', '');
        $this->setEnv('SYNAPLAN_TTS_URL', '');

        $report = $this->inventory(
            chatReady: false,
            models: [],
            brave: false,
            billing: false,
        )->build(2);

        $chat = $report->fact('chat');
        $this->assertNotNull($chat);
        $this->assertSame(CapabilityState::NeedsSetup, $chat->state);

        $pdf = $report->fact('pdf_export');
        $this->assertNotNull($pdf);
        $this->assertSame(CapabilityState::NeedsSetup, $pdf->state);
        $this->assertNotNull($pdf->alternative);

        $memories = $report->fact('memories');
        $this->assertNotNull($memories);
        $this->assertSame(CapabilityState::NeedsSetup, $memories->state);

        $documents = $report->fact('document_generation');
        $this->assertNotNull($documents);
        $this->assertSame(CapabilityState::Available, $documents->state);
        foreach (PlatformCapabilityInventory::KNOWN_ABSENT as $row) {
            $fact = $report->fact($row['id']);
            $this->assertNotNull($fact);
            $this->assertSame(CapabilityState::Absent, $fact->state);
            $this->assertNotNull($fact->alternative);
        }
        $this->assertSame('original lyrics in that style', $report->fact('music_generation')?->alternative);
        $this->assertFalse($report->billingEnabled);
    }

    public function testNoEngineInstallHasChatAndImagesButNotPdfOrVideo(): void
    {
        $this->setEnv('QDRANT_URL', 'http://qdrant:6333');
        $this->setEnv('OFFICE_CONVERT_URL', '');
        $this->setEnv('SYNAPLAN_TTS_URL', '');

        $report = $this->inventory(
            chatReady: true,
            models: ['PIC2TEXT' => 1, 'VECTORIZE' => 2, 'TEXT2PIC' => 3, 'SOUND2TEXT' => 4],
            brave: true,
            billing: false,
        )->build(2);

        $this->assertSame(CapabilityState::Available, $report->fact('chat')?->state);
        $this->assertSame(CapabilityState::Available, $report->fact('image_generation')?->state);
        $this->assertSame(CapabilityState::Available, $report->fact('memories')?->state);
        $this->assertSame(CapabilityState::Available, $report->fact('web_search')?->state);
        $this->assertSame(CapabilityState::NeedsSetup, $report->fact('video_generation')?->state);
        $this->assertSame(CapabilityState::NeedsSetup, $report->fact('pdf_export')?->state);
        $this->assertSame(CapabilityState::NeedsSetup, $report->fact('text_to_speech')?->state);
        $this->assertSame('original lyrics in that style', $report->fact('music_generation')?->alternative);
    }

    public function testFullInstallMarksPdfAndTtsAvailableAndUpgradesMusicAlternative(): void
    {
        $this->setEnv('QDRANT_URL', 'http://qdrant:6333');
        $this->setEnv('OFFICE_CONVERT_URL', 'http://office:8080');
        $this->setEnv('SYNAPLAN_TTS_URL', 'http://tts:8090');

        $report = $this->inventory(
            chatReady: true,
            models: [
                'PIC2TEXT' => 1,
                'VECTORIZE' => 2,
                'TEXT2PIC' => 3,
                'TEXT2VID' => 5,
                'TEXT2SOUND' => 6,
                'SOUND2TEXT' => 4,
            ],
            brave: true,
            billing: true,
        )->build(2);

        $this->assertSame(CapabilityState::Available, $report->fact('pdf_export')?->state);
        $this->assertSame(CapabilityState::Available, $report->fact('text_to_speech')?->state);
        $this->assertSame(CapabilityState::Available, $report->fact('video_generation')?->state);
        $this->assertSame('original lyrics, read aloud as MP3', $report->fact('music_generation')?->alternative);
        $this->assertTrue($report->billingEnabled);
        $this->assertSame('4.2.1', $report->version);
    }

    /**
     * @param array<string, int> $models
     */
    private function inventory(bool $chatReady, array $models, bool $brave, bool $billing): PlatformCapabilityInventory
    {
        $chatReadiness = $this->createMock(ChatReadinessService::class);
        $chatReadiness->method('isChatReady')->willReturn($chatReady);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturnCallback(
            static fn (string $capability, ?int $userId): ?int => $models[$capability] ?? null,
        );

        $vectorStorage = $this->createMock(VectorStorageFacade::class);
        $vectorStorage->method('getProviderName')->willReturn('qdrant');

        $braveSearch = $this->createMock(BraveSearchService::class);
        $braveSearch->method('isEnabled')->willReturn($brave);

        $routing = $this->createMock(MultitaskRoutingConfig::class);
        $routing->method('isFeatureEnabled')->willReturnCallback(
            static fn (string $setting, ?int $userId, bool $default): bool => $default,
        );

        $this->setEnv('MAILER_DSN', '');
        $mailer = new MailerConfig();

        $savedTasks = $this->createMock(SavedTaskConfig::class);
        $savedTasks->method('isEnabled')->willReturn(true);

        $desktop = $this->createMock(DesktopAgentConfig::class);
        $desktop->method('isEnabled')->willReturn(false);

        $mcp = $this->createMock(McpClientConfig::class);
        $mcp->method('isClientEnabled')->willReturn(false);

        $plugins = $this->createMock(PluginManager::class);
        $plugins->method('listInstalledPlugins')->willReturn([]);

        $update = $this->createMock(UpdateStatusService::class);
        $update->method('getStatus')->willReturn([
            'currentVersion' => '4.2.1',
            'latestVersion' => null,
            'updateAvailable' => false,
        ]);

        $billingService = $this->createMock(BillingService::class);
        $billingService->method('isEnabled')->willReturn($billing);

        $connections = $this->createMock(ConnectionRepository::class);
        $connections->method('findByOwner')->willReturn([]);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('getTopicsWithDescriptions')->willReturn([]);

        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('hasVerifiedPhone')->willReturn(false);
        $user->method('getUserDetails')->willReturn([]);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        return new PlatformCapabilityInventory(
            $chatReadiness,
            $modelConfig,
            $vectorStorage,
            $braveSearch,
            $routing,
            $mailer,
            $savedTasks,
            $desktop,
            $mcp,
            $plugins,
            new CapabilityService(),
            $prompts,
            $update,
            $billingService,
            $connections,
            $users,
            false,
            '',
        );
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $existing = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}

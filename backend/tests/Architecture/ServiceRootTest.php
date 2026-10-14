<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Code cleanup V1 (C2) — the Service root is the plugin contract.
 *
 * A file directly in src/Service/ is either part of the de-facto plugin SDK
 * (PERMANENT) or on its way into a domain folder (PENDING_MOVE). Anything
 * else fails the test: new services start in their domain folder, never in
 * the root. The PENDING_MOVE list is a dated allow-list that only shrinks —
 * every move PR deletes its names here, and a name whose file no longer
 * exists fails the second test so the list cannot rot.
 *
 * Plan: _devextras/planning/20261014-code-cleanup-V1/ (master plan §3,
 * sprints C2–C8, 02_service_root_map.md for the destination of each file).
 */
final class ServiceRootTest extends TestCase
{
    /**
     * Imported by external plugins (Synaform, synafastbill, Synamail,
     * synaScriber) and the in-repo plugins/castingdata. Moving one is a
     * breaking change for plugin authors and needs a class_alias window
     * (V3, master plan §8). The four stay in the root on purpose.
     */
    private const PERMANENT = [
        'ModelConfigService',
        'OidcUserService',
        'PluginDataService',
        'RateLimitService',
    ];

    /**
     * Loose files still waiting for their move PR, grouped by the step that
     * removes them (02_service_root_map.md). Delete a name in the same PR
     * that moves the file. Only shrinks; never add to it.
     */
    private const PENDING_MOVE = [
        // C3 — Widget/
        'DatabaseWidgetEventStore',
        'HumanTakeoverService',
        'WidgetEventStoreInterface',
        'WidgetExportService',
        'WidgetRealtimeBroadcaster',
        'WidgetService',
        'WidgetSessionService',
        'WidgetSetupService',
        'WidgetSummaryService',
        'WordPressIntegrationService',
        // C4 — Email/, WhatsApp/, Notification/
        'DiscordNotificationService',
        'EmailChatService',
        'EmailWebhookIdempotencyService',
        'InboundEmailHandlerService',
        'InboundEmailService',
        'InternalEmailService',
        'MailHandlerLogService',
        'MailerConfig',
        'SlackNotificationService',
        'WhatsAppService',
        // C5 — Auth/, OAuth/, Iam/, App\Model\
        'AccountLanguage',
        'ImpersonationService',
        'JwtValidator',
        'NativeAuthHandoffService',
        'OAuthLoginResponder',
        'OAuthStateService',
        'OidcScopeResolver',
        'OidcTokenService',
        'RecaptchaService',
        'RegistrationConfig',
        'TokenService',
        'UserDeletionService',
        'UserLifecycleService',
        // C6 — Usage/, Billing/, Iap/
        'BillingService',
        'CachePriceResolver',
        'CostCalculationService',
        'IapPricingService',
        'MobilePurchaseService',
        'PremiumFeatureGate',
        'StorageQuotaService',
        'UsageStatsService',
        'UsageTaximeterConfig',
        // C7 — Message/, Chat/, Memory/, Feedback/, Research/
        'AiResponseSanitizer',
        'ContentModerationService',
        'ConversationSummaryRefreshDispatcher',
        'FeedbackConfigService',
        'FeedbackConstants',
        'FeedbackContradictionService',
        'FeedbackExampleService',
        'GuestChatConfig',
        'GuestSessionService',
        'MemoryExtractionDispatcher',
        'MemoryExtractionService',
        'MessageEnqueueService',
        'PastedContentText',
        'PerfPipelineFlag',
        'UrlContentService',
        'UserMemoryService',
        // C8 — Media/, Stt/, Prompt/, Security/, Infrastructure/, App\Monolog\
        'CircuitBreaker',
        'EncryptionService',
        'MediaGenerationService',
        'MediaGenerationServiceInterface',
        'MonologFormatterFactory',
        'PerfTimer',
        'PromptService',
        'SpeechFailure',
        'TtsTextSanitizer',
        'WebSpeechConfig',
        'WhisperService',
    ];

    public function testEveryRootFileIsPermanentOrPendingMove(): void
    {
        $allowed = array_merge(self::PERMANENT, self::PENDING_MOVE);
        $unexpected = array_values(array_diff($this->rootClassNames(), $allowed));

        $this->assertSame([], $unexpected, sprintf(
            "Unexpected file(s) directly in src/Service/:\n  %s\n\nPut it in a domain folder (src/Service/<Domain>/), or add it to PERMANENT with the external plugin that imports it.",
            implode("\n  ", $unexpected),
        ));
    }

    public function testPendingMoveEntriesStillExist(): void
    {
        $existing = $this->rootClassNames();
        $moved = array_values(array_diff(self::PENDING_MOVE, $existing));

        $this->assertSame([], $moved, sprintf(
            "PENDING_MOVE lists file(s) that are no longer in src/Service/:\n  %s\n\nIt moved — delete it from PENDING_MOVE in the same PR; the list only shrinks.",
            implode("\n  ", $moved),
        ));
    }

    public function testPermanentEntriesStillExist(): void
    {
        $missing = array_values(array_diff(self::PERMANENT, $this->rootClassNames()));

        $this->assertSame([], $missing, sprintf(
            "PERMANENT plugin-SDK class(es) are no longer in src/Service/:\n  %s\n\nExternal plugins import these by FQCN; moving one needs a class_alias window (master plan §8).",
            implode("\n  ", $missing),
        ));
    }

    public function testNoNameIsListedTwice(): void
    {
        $all = array_merge(self::PERMANENT, self::PENDING_MOVE);
        $duplicates = array_keys(array_filter(array_count_values($all), static fn (int $count): bool => $count > 1));

        $this->assertSame([], $duplicates, 'Each root file is either PERMANENT or PENDING_MOVE, not both: '.implode(', ', $duplicates));
    }

    /**
     * @return list<string> class names (basename without .php) of the files directly in src/Service/, sorted
     */
    private function rootClassNames(): array
    {
        $files = glob(dirname(__DIR__, 2).'/src/Service/*.php');
        $this->assertNotFalse($files, 'glob() failed on src/Service/*.php');

        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        sort($names);

        return $names;
    }
}

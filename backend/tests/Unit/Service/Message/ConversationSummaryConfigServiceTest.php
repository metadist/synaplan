<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Repository\ConfigRepository;
use App\Service\Message\ConversationSummaryConfigService;
use App\Service\Message\ConversationSummaryConstants;
use PHPUnit\Framework\TestCase;

/**
 * Locks the BCONFIG-backed defaults + clamping for the rolling summary settings.
 */
class ConversationSummaryConfigServiceTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     */
    private function makeService(array $overrides = []): ConversationSummaryConfigService
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => $overrides[$setting] ?? null,
        );

        return new ConversationSummaryConfigService($repo);
    }

    public function testDefaultsWhenNothingConfigured(): void
    {
        $service = $this->makeService();

        self::assertTrue($service->isEnabled());
        self::assertSame(ConversationSummaryConstants::TARGET_WINDOW_CHARS, $service->getTargetWindowChars());
        self::assertSame(ConversationSummaryConstants::RECENT_VERBATIM_CHARS, $service->getRecentVerbatimChars());
        self::assertSame(ConversationSummaryConstants::SUMMARY_MAX_CHARS, $service->getSummaryMaxChars());
        self::assertSame(ConversationSummaryConstants::TIERS, $service->getTiers());
        self::assertSame(ConversationSummaryConstants::MAX_SOURCE_MESSAGES, $service->getMaxSourceMessages());
    }

    public function testEnabledFlagParsing(): void
    {
        self::assertFalse($this->makeService(['ENABLED' => '0'])->isEnabled());
        self::assertFalse($this->makeService(['ENABLED' => 'false'])->isEnabled());
        self::assertTrue($this->makeService(['ENABLED' => 'yes'])->isEnabled());
        self::assertTrue($this->makeService(['ENABLED' => '1'])->isEnabled());
    }

    public function testTargetWindowIsClampedToTenToFifteenKBand(): void
    {
        self::assertSame(
            ConversationSummaryConstants::MAX_WINDOW_CHARS,
            $this->makeService(['TARGET_WINDOW_CHARS' => '99999'])->getTargetWindowChars(),
        );
        self::assertSame(
            ConversationSummaryConstants::MIN_WINDOW_CHARS,
            $this->makeService(['TARGET_WINDOW_CHARS' => '1000'])->getTargetWindowChars(),
        );
    }

    public function testRecentVerbatimNeverConsumesWholeWindow(): void
    {
        // Window clamps to 10 000; recent asks for 20 000 → capped at window-500.
        $service = $this->makeService([
            'TARGET_WINDOW_CHARS' => '10000',
            'RECENT_VERBATIM_CHARS' => '20000',
        ]);

        self::assertSame(9500, $service->getRecentVerbatimChars());
        // Summary keeps at least its floor even when recent is greedy.
        self::assertSame(500, $service->getSummaryMaxChars());
    }

    public function testSummaryPlusRecentStaysWithinWindow(): void
    {
        $service = $this->makeService([
            'TARGET_WINDOW_CHARS' => '15000',
            'RECENT_VERBATIM_CHARS' => '9000',
            'SUMMARY_MAX_CHARS' => '4000',
        ]);

        self::assertLessThanOrEqual(
            $service->getTargetWindowChars(),
            $service->getRecentVerbatimChars() + $service->getSummaryMaxChars(),
        );
    }
}

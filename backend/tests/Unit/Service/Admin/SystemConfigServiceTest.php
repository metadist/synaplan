<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\AI\Credential\ProviderKeyStore;
use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfigService;
use App\Service\EncryptionService;
use App\Service\Message\ConversationSummaryConstants;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Focused tests for SystemConfigService's database-backed config writes —
 * notably the multi-task routing master switch and the rolling-summary knobs,
 * which use a dbGroup/dbKey override to target a row outside the default group.
 */
final class SystemConfigServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private SystemConfigService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);

        // ProviderKeyStore is final (not mockable); a real instance over the
        // same repository mock is inert for these multitask-focused tests.
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $providerKeyStore = new ProviderKeyStore(
            $this->configRepository,
            $encryption,
            new NullLogger(),
        );

        $this->service = new SystemConfigService(
            projectDir: sys_get_temp_dir(),
            logger: new NullLogger(),
            configRepository: $this->configRepository,
            defaultTtsUrl: 'http://localhost:10200',
            providerKeyStore: $providerKeyStore,
            encryption: $encryption,
        );
    }

    /**
     * The multi-task master switch is exposed under the flat admin key
     * MULTITASK_ROUTING_ENABLED but MUST write to the BCONFIG row that
     * MultitaskRoutingConfig reads: group MULTITASK / setting ROUTING_ENABLED
     * (not the default QDRANT_SEARCH group). This is the dbGroup/dbKey override.
     */
    public function testMultitaskRoutingWritesToTheMultitaskGroupRow(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, 'MULTITASK', 'ROUTING_ENABLED', 'true');

        $result = $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true', 7);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['requiresRestart']);
    }

    /**
     * A database-backed secret must never reach BCONFIG in plain text — the row
     * is readable by anyone with DB access and by every other config reader.
     */
    public function testM365ClientSecretIsEncryptedBeforeItIsStored(): void
    {
        $stored = null;
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->willReturnCallback(function (int $owner, string $group, string $setting, string $value) use (&$stored) {
                self::assertSame(0, $owner);
                self::assertSame(MicrosoftOAuthConfig::CONFIG_GROUP, $group);
                self::assertSame(MicrosoftOAuthConfig::KEY_CLIENT_SECRET, $setting);
                $stored = $value;

                return new Config();
            });

        $result = $this->service->setValue('M365_CLIENT_SECRET', 'super-secret');

        self::assertTrue($result['success']);
        self::assertIsString($stored);
        self::assertNotSame('super-secret', $stored);
        self::assertSame(
            'super-secret',
            (new EncryptionService('test-secret', new NullLogger()))->decrypt($stored),
        );
    }

    public function testM365ClientSecretIsMaskedWhenRead(): void
    {
        $this->configRepository->expects($this->atLeastOnce())->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => MicrosoftOAuthConfig::CONFIG_GROUP === $group
                && MicrosoftOAuthConfig::KEY_CLIENT_SECRET === $setting
                    ? 'some-ciphertext'
                    : null
        );

        $secret = $this->service->getValues()['M365_CLIENT_SECRET'];

        self::assertTrue($secret['isSet']);
        self::assertTrue($secret['isMasked']);
        self::assertStringNotContainsString('some-ciphertext', $secret['value']);
    }

    /**
     * Existing users were grandfathered to an explicit per-user OFF row that
     * overrides the global flag. When an admin toggles the global switch we
     * drop their own per-user override so the value applies to them too.
     */
    public function testEnablingClearsActingAdminPerUserOverride(): void
    {
        $this->configRepository->expects($this->once())
            ->method('deleteValue')
            ->with(7, 'MULTITASK', 'ROUTING_ENABLED');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true', 7);
    }

    public function testDisablingAlsoClearsActingAdminPerUserOverride(): void
    {
        $this->configRepository->expects($this->once())
            ->method('deleteValue')
            ->with(7, 'MULTITASK', 'ROUTING_ENABLED');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'false', 7);
    }

    public function testMultitaskWriteWithoutActingUserDoesNotDeleteAnything(): void
    {
        $this->configRepository->expects($this->never())->method('deleteValue');

        $this->service->setValue('MULTITASK_ROUTING_ENABLED', 'true');
    }

    /**
     * Reads must also resolve through the dbGroup/dbKey override so the admin
     * UI reflects the actual MultitaskRoutingConfig row.
     */
    public function testGetValuesReadsMultitaskFlagFromTheMultitaskGroup(): void
    {
        $this->configRepository->expects($this->atLeastOnce())
            ->method('getValue')
            ->willReturnCallback(
                static fn (int $owner, string $group, string $setting): ?string => 'MULTITASK' === $group && 'ROUTING_ENABLED' === $setting ? 'false' : null
            );

        $values = $this->service->getValues();

        $this->assertSame('false', $values['MULTITASK_ROUTING_ENABLED']['value']);
        $this->assertTrue($values['MULTITASK_ROUTING_ENABLED']['isSet']);
    }

    public function testGetValuesExposesActingAdminsEffectiveRoutingOverride(): void
    {
        $this->configRepository->expects($this->atLeastOnce())
            ->method('getValue')
            ->willReturnCallback(
                static function (int $owner, string $group, string $setting): ?string {
                    if ('MULTITASK' !== $group || 'ROUTING_ENABLED' !== $setting) {
                        return null;
                    }

                    return 7 === $owner ? 'false' : 'true';
                }
            );

        $values = $this->service->getValues(7);
        $routing = $values['MULTITASK_ROUTING_ENABLED'];

        $this->assertSame('true', $routing['value']);
        $this->assertTrue($routing['hasPersonalOverride']);
        $this->assertSame('false', $routing['effectiveForMe']);
    }

    public function testGetValuesFallsBackToGlobalRoutingValueWithoutPersonalOverride(): void
    {
        $this->configRepository->expects($this->atLeastOnce())
            ->method('getValue')
            ->willReturnCallback(
                static fn (int $owner, string $group, string $setting): ?string => 0 === $owner
                    && 'MULTITASK' === $group
                    && 'ROUTING_ENABLED' === $setting
                        ? 'true'
                        : null
            );

        $values = $this->service->getValues(7);
        $routing = $values['MULTITASK_ROUTING_ENABLED'];

        $this->assertSame('true', $routing['value']);
        $this->assertFalse($routing['hasPersonalOverride']);
        $this->assertSame('true', $routing['effectiveForMe']);
    }

    /**
     * The rolling-summary knobs are exposed under flat CONVERSATION_SUMMARY_*
     * admin keys but must land in the BCONFIG rows
     * ConversationSummaryConfigService reads: group CONVERSATION_SUMMARY with
     * the bare setting name.
     */
    public function testConversationSummaryWritesToTheSummaryGroupRow(): void
    {
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(0, ConversationSummaryConstants::CONFIG_GROUP, 'RECENT_VERBATIM_CHARS', '6000');

        $result = $this->service->setValue('CONVERSATION_SUMMARY_RECENT_VERBATIM_CHARS', '6000', 7);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['requiresRestart']);
    }

    /**
     * ConversationSummaryConfigService discards a non-positive value and uses
     * its constant default, so storing one would be a silent no-op.
     */
    public function testConversationSummaryRejectsNonPositiveSizes(): void
    {
        $this->configRepository->expects($this->never())->method('setValue');

        $result = $this->service->setValue('CONVERSATION_SUMMARY_TIERS', '0', 7);

        $this->assertFalse($result['success']);
    }

    public function testConversationSummaryDefaultsMirrorTheConstants(): void
    {
        // No BCONFIG rows exist by default (there is no seeder), so what the
        // admin UI shows has to be what the code actually falls back to.
        $this->configRepository->method('getValue')->willReturn(null);

        $values = $this->service->getValues();

        $this->assertFalse($values['CONVERSATION_SUMMARY_ENABLED']['isSet']);
        $this->assertSame(
            var_export(ConversationSummaryConstants::ENABLED, true),
            $values['CONVERSATION_SUMMARY_ENABLED']['value'],
        );
        $this->assertSame(
            (string) ConversationSummaryConstants::RECENT_VERBATIM_CHARS,
            $values['CONVERSATION_SUMMARY_RECENT_VERBATIM_CHARS']['value'],
        );
        $this->assertSame(
            (string) ConversationSummaryConstants::TIERS,
            $values['CONVERSATION_SUMMARY_TIERS']['value'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setup;

use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Setup\SetupConstants;
use App\Service\Setup\SetupStateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The invariants that decide whether a running installation can be taken over by
 * whoever loads its URL first. Each test here corresponds to a way that could go
 * wrong in production.
 */
final class SetupStateServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private UserRepository&MockObject $userRepository;
    private ?string $originalEnv = null;
    private bool $envWasSet = false;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->envWasSet = \array_key_exists('SETUP_WIZARD_ENABLED', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['SETUP_WIZARD_ENABLED'] : null;
        unset($_ENV['SETUP_WIZARD_ENABLED']);
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['SETUP_WIZARD_ENABLED'] = $this->originalEnv;
        } else {
            unset($_ENV['SETUP_WIZARD_ENABLED']);
        }
    }

    public function testAVirginInstallationNeedsSetup(): void
    {
        $this->givenFlag(null);
        $this->givenUserCount(0);

        self::assertTrue($this->service()->isSetupRequired());
    }

    /**
     * The flag is what every upgraded installation gets from the backfill
     * migration. It has to win outright, even against an empty user table — a
     * restored-but-not-yet-populated database must not open the wizard.
     */
    public function testTheCompletedFlagBeatsAnEmptyUserTable(): void
    {
        $this->givenFlag('1');
        $this->userRepository->expects($this->never())->method('countAll');

        self::assertFalse($this->service()->isSetupRequired());
    }

    /**
     * The runtime safety net for an installation whose flag is missing (backup
     * restore, row deleted by hand). ANY user closes the window, including the
     * BUSERLEVEL='ANONYMOUS' rows that the public email/WhatsApp webhooks create
     * — which is exactly the row a stranger could otherwise plant.
     */
    public function testAnySingleUserClosesTheWindowWithoutTheFlag(): void
    {
        $this->givenFlag(null);
        $this->givenUserCount(1);

        self::assertFalse($this->service()->isSetupRequired());
    }

    /**
     * Deleting every administrator must NOT reopen the wizard. A wizard that
     * comes back on a populated instance is a takeover waiting to happen;
     * `app:admin:reset-password` is the recovery path instead.
     */
    public function testLosingEveryAdminDoesNotReopenTheWizard(): void
    {
        $this->givenFlag('1');

        self::assertFalse($this->service()->isSetupRequired());
    }

    public function testTheKillSwitchOverridesEverything(): void
    {
        $_ENV['SETUP_WIZARD_ENABLED'] = 'false';
        $this->configRepository->expects($this->never())->method('getValue');
        $this->userRepository->expects($this->never())->method('countAll');

        self::assertFalse($this->service()->isSetupRequired());
    }

    public function testAnEmptyKillSwitchValueKeepsTheWizardAvailable(): void
    {
        $_ENV['SETUP_WIZARD_ENABLED'] = '';
        $this->givenFlag(null);
        $this->givenUserCount(0);

        self::assertTrue($this->service()->isSetupRequired());
    }

    /**
     * ConfigRepository does not cache, and the lockdown subscriber asks on every
     * request, so the answer is memoized. One read per request, not one per
     * caller.
     */
    public function testTheDecisionIsMemoizedWithinOneRequest(): void
    {
        $this->configRepository->expects($this->once())->method('getValue')->willReturn(null);
        $this->userRepository->expects($this->once())->method('countAll')->willReturn(0);

        $service = $this->service();
        self::assertTrue($service->isSetupRequired());
        self::assertTrue($service->isSetupRequired());
        self::assertTrue($service->isSetupRequired());
    }

    public function testMarkCompletedWritesTheInstallWideRowOnce(): void
    {
        $this->givenFlag(null);
        $this->configRepository->expects($this->once())
            ->method('setValue')
            ->with(
                SetupConstants::OWNER_ID,
                SetupConstants::CONFIG_GROUP,
                SetupConstants::KEY_COMPLETED,
                '1',
            );

        $service = $this->service();
        $service->markCompleted();
        // A second call must not write again — the in-memory state is authoritative
        // for the rest of the request.
        $service->markCompleted();

        self::assertFalse($service->isSetupRequired());
    }

    public function testMarkCompletedSkipsTheWriteWhenTheFlagIsAlreadyThere(): void
    {
        $this->givenFlag('1');
        $this->configRepository->expects($this->never())->method('setValue');

        $this->service()->markCompleted();
    }

    /**
     * FrankenPHP keeps the service alive across requests. Without a reset the
     * first "setup required" answer would stick, and /auth/me after creating
     * the administrator would keep returning 503.
     */
    public function testResetClearsTheMemoSoTheNextRequestSeesTheNewUser(): void
    {
        $this->configRepository->expects($this->exactly(2))->method('getValue')->willReturn(null);
        $this->userRepository->expects($this->exactly(2))->method('countAll')
            ->willReturnOnConsecutiveCalls(0, 1);

        $service = $this->service();
        self::assertTrue($service->isSetupRequired());

        $service->reset();

        self::assertFalse($service->isSetupRequired());
    }

    private function service(): SetupStateService
    {
        return new SetupStateService($this->configRepository, $this->userRepository);
    }

    private function givenFlag(?string $value): void
    {
        $this->configRepository->expects($this->any())
            ->method('getValue')
            ->with(SetupConstants::OWNER_ID, SetupConstants::CONFIG_GROUP, SetupConstants::KEY_COMPLETED)
            ->willReturn($value);
    }

    private function givenUserCount(int $count): void
    {
        $this->userRepository->expects($this->any())->method('countAll')->willReturn($count);
    }
}

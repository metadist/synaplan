<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides whether this installation still needs its first-run setup.
 *
 * isSetupRequired() = wizardEnabled && !completed && countAllUsers() === 0
 *
 * Three independent safeguards keep an EXISTING installation out of the wizard,
 * and one of them is enough:
 *
 *   1. The BCONFIG flag, written by the backfill migration on every upgrade of a
 *      database that shows any sign of prior use.
 *   2. The user count. An install with users can never fall into setup mode,
 *      even if the flag is missing (restored backup, row deleted by hand).
 *   3. SETUP_WIZARD_ENABLED=false, the operator kill switch.
 *
 * Two invariants that tests pin down:
 *
 *   - ALL BUSER rows count, including BUSERLEVEL='ANONYMOUS'. Deliberately
 *     stricter than "real accounts only" so an instance that runs purely as an
 *     email/WhatsApp bot never sees a wizard.
 *   - The flag is never cleared automatically. Deleting every admin later does
 *     NOT reopen the wizard, because that would let a stranger claim a running
 *     instance; recovery goes through `app:admin:reset-password`.
 *
 * Not `readonly`: the result is memoized per request because
 * {@see ConfigRepository} does not cache and the lockdown subscriber asks on
 * every single request. {@see ResetInterface} clears that memo between
 * requests so a FrankenPHP worker does not keep answering "setup required"
 * after the first administrator already exists.
 */
final class SetupStateService implements ResetInterface
{
    private ?bool $completed = null;

    private ?bool $required = null;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Operator kill switch. Unset or empty keeps the wizard available, matching
     * the env parsing of {@see \App\Service\RegistrationConfig}.
     */
    public function isWizardEnabled(): bool
    {
        $raw = trim((string) ($_ENV['SETUP_WIZARD_ENABLED'] ?? ''));

        if ('' === $raw) {
            return true;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function isCompleted(): bool
    {
        if (null !== $this->completed) {
            return $this->completed;
        }

        $value = $this->configRepository->getValue(
            SetupConstants::OWNER_ID,
            SetupConstants::CONFIG_GROUP,
            SetupConstants::KEY_COMPLETED,
        );

        return $this->completed = null !== $value
            && (filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }

    public function isSetupRequired(): bool
    {
        if (null !== $this->required) {
            return $this->required;
        }

        if (!$this->isWizardEnabled()) {
            return $this->required = false;
        }

        if ($this->isCompleted()) {
            return $this->required = false;
        }

        return $this->required = 0 === $this->userRepository->countAll();
    }

    /**
     * Closes the setup window for good. Called by the wizard's final step and by
     * {@see \App\Service\Admin\BootstrapAdminService} so the headless
     * BOOTSTRAP_ADMIN_* path never shows a wizard either.
     */
    public function markCompleted(): void
    {
        if (!$this->isCompleted()) {
            $this->configRepository->setValue(
                SetupConstants::OWNER_ID,
                SetupConstants::CONFIG_GROUP,
                SetupConstants::KEY_COMPLETED,
                '1',
            );
        }

        $this->completed = true;
        $this->required = false;
    }

    public function reset(): void
    {
        $this->completed = null;
        $this->required = null;
    }
}

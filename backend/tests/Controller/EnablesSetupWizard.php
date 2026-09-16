<?php

declare(strict_types=1);

namespace App\Tests\Controller;

/**
 * Lifts the `SETUP_WIZARD_ENABLED=false` guard from `.env.test` for one test.
 *
 * The guard exists because the PHPUnit database has no fixtures, which is
 * indistinguishable from a virgin installation — without it every functional test
 * in the suite would be answered with 503 SETUP_REQUIRED. The tests that are
 * ABOUT the wizard are the ones that need it back.
 *
 * {@see \App\Service\Setup\SetupStateService::isWizardEnabled()} reads `$_ENV` on
 * every call, so flipping it after the kernel booted takes effect immediately.
 */
trait EnablesSetupWizard
{
    private ?string $wizardEnabledBefore = null;

    private bool $wizardEnvCaptured = false;

    private bool $wizardEnvWasSet = false;

    protected function enableSetupWizard(): void
    {
        $this->captureWizardEnv();
        $_ENV['SETUP_WIZARD_ENABLED'] = 'true';
    }

    protected function disableSetupWizard(): void
    {
        $this->captureWizardEnv();
        $_ENV['SETUP_WIZARD_ENABLED'] = 'false';
    }

    /**
     * A test that never touched the variable must leave it exactly as it found
     * it. Restoring unconditionally would unset the value inherited from
     * `.env.test` and hand the next test in the same PHP process an enabled
     * wizard.
     */
    protected function restoreSetupWizardEnv(): void
    {
        if (!$this->wizardEnvCaptured) {
            return;
        }

        if ($this->wizardEnvWasSet) {
            $_ENV['SETUP_WIZARD_ENABLED'] = $this->wizardEnabledBefore;
        } else {
            unset($_ENV['SETUP_WIZARD_ENABLED']);
        }

        $this->wizardEnvCaptured = false;
    }

    private function captureWizardEnv(): void
    {
        if ($this->wizardEnvCaptured) {
            return;
        }

        $this->wizardEnvWasSet = \array_key_exists('SETUP_WIZARD_ENABLED', $_ENV);
        $this->wizardEnabledBefore = $this->wizardEnvWasSet
            ? (string) $_ENV['SETUP_WIZARD_ENABLED']
            : null;
        $this->wizardEnvCaptured = true;
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The rules an operator-supplied local password has to satisfy.
 *
 * Two paths let somebody with deployment or shell access set a password without
 * ever seeing the sign-up form: BOOTSTRAP_ADMIN_PASSWORD, validated by
 * {@see \App\Service\Admin\BootstrapAdminConfiguration}, and
 * `app:admin:reset-password`. Both reach the same account — the one that can
 * see everything — so the rule lives here once instead of being restated per
 * caller, where the recovery path could quietly end up more lenient than the
 * policy the documentation promises.
 *
 * No dependencies on purpose: the container entrypoint decides the bootstrap
 * rules in a bare `php -r` with nothing loaded but the Composer autoloader (see
 * require_valid_bootstrap_admin_config in _docker/backend/lib/container-runtime.sh).
 */
final class PasswordPolicy
{
    public const MINIMUM_LENGTH = 8;
    public const MAXIMUM_LENGTH = 64;

    /**
     * Following NIST SP 800-63B, composition rules only apply to short
     * passwords. Long values are waived because managed platforms (for example
     * Elestio) inject a high-entropy generated password and show it to the
     * operator as the login credential: such a value can legitimately lack a
     * digit, and rejecting it would crash-loop the whole deployment.
     */
    public const COMPOSITION_WAIVER_LENGTH = 16;

    public const COMPOSITION_PATTERN = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/';

    /**
     * The first rule the password breaks, phrased for the caller's own vocabulary.
     *
     * A returned message never contains the password: the container guard prints
     * it verbatim into the boot log, and the console command prints it to a
     * terminal that keeps scrollback.
     *
     * @param string $subject how the message names the value — an environment
     *                        variable name for a deployment, "The password" for
     *                        somebody typing a command
     *
     * @return string|null null when the password is acceptable
     */
    public static function violation(
        #[\SensitiveParameter]
        string $password,
        string $subject,
    ): ?string {
        // Bytes, not characters. The lengths have always been measured this way
        // on the bootstrap path, and tightening them would reject a password
        // that a running deployment starts with today.
        $length = strlen($password);

        if ($length < self::MINIMUM_LENGTH) {
            return sprintf('%s must be at least %d characters.', $subject, self::MINIMUM_LENGTH);
        }

        if ($length > self::MAXIMUM_LENGTH) {
            return sprintf('%s must be at most %d characters.', $subject, self::MAXIMUM_LENGTH);
        }

        if ($length < self::COMPOSITION_WAIVER_LENGTH && 1 !== preg_match(self::COMPOSITION_PATTERN, $password)) {
            return sprintf(
                '%s must contain at least one uppercase letter, one lowercase letter, and one number, or be at least %d characters long.',
                $subject,
                self::COMPOSITION_WAIVER_LENGTH,
            );
        }

        return null;
    }
}

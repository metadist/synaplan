<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * The validated first-admin bootstrap configuration.
 *
 * This class is the single authority for the BOOTSTRAP_ADMIN_EMAIL and
 * BOOTSTRAP_ADMIN_PASSWORD rules. It deliberately has no dependencies — not on
 * the container, the database or the Symfony kernel, and not even on a sibling
 * class — so the exact same rules can be decided in three places without a
 * second implementation that could drift:
 *
 *   1. the container entrypoint, seconds after start and before the database
 *      wait, the migrations and the seeders (see require_valid_bootstrap_admin_config
 *      in _docker/backend/lib/container-runtime.sh);
 *   2. BootstrapAdminService, which performs the actual bootstrap;
 *   3. the host-side deployment preflight, through the application image.
 *
 * Self-contained to the file, not just to the runtime: the deployment contract
 * test mounts THIS file alone into a bare PHP image as the autoload path (see
 * assert_bootstrap_contract in deploy/scripts/tests/test-lifecycle.sh), so a
 * `use` of anything under App\ turns every case into a fatal error.
 *
 * {@see passwordViolation()} is public because `app:admin:reset-password` sets a
 * password for the same account without ever passing through the sign-up form.
 * A rule restated there would be free to end up the lenient one.
 *
 * Values are only ever validated here, never echoed: the password is a secret,
 * so no rule violation message may contain it.
 */
final readonly class BootstrapAdminConfiguration
{
    public const MAXIMUM_EMAIL_LENGTH = 128;
    public const MINIMUM_PASSWORD_LENGTH = 8;
    public const MAXIMUM_PASSWORD_LENGTH = 64;

    /**
     * Following NIST SP 800-63B, composition rules only apply to short
     * passwords. Long values are waived because managed platforms (for example
     * Elestio) inject a high-entropy generated password and show it to the
     * operator as the login credential: such a value can legitimately lack a
     * digit, and rejecting it would crash-loop the whole deployment.
     */
    public const COMPOSITION_WAIVER_LENGTH = 16;

    /**
     * The accepted password, wrapped so that only password() can read it.
     *
     * A public string property would be printed verbatim by anything that ever
     * receives this object — var_dump(), json_encode(), a log context, a
     * Symfony error page. A SensitiveParameterValue exposes nothing to any of
     * them and refuses serialization outright, which #[\SensitiveParameter] on
     * a promoted property cannot do: that attribute only redacts stack traces.
     */
    private \SensitiveParameterValue $password;

    private function __construct(
        public string $email,
        #[\SensitiveParameter]
        string $acceptedPassword,
    ) {
        $this->password = new \SensitiveParameterValue($acceptedPassword);
    }

    /**
     * The plaintext password, for the single caller that has to hash it.
     */
    public function password(): string
    {
        $password = $this->password->getValue();
        \assert(\is_string($password));

        return $password;
    }

    /**
     * Validates raw deployment configuration.
     *
     * Both values empty is a valid choice and means "do not bootstrap an
     * administrator"; exactly one of them is an operator error.
     *
     * @return self|null null when the bootstrap is not configured at all
     *
     * @throws \InvalidArgumentException when the configuration is incomplete or breaks a rule
     */
    public static function fromConfiguration(
        string $configuredEmail,
        #[\SensitiveParameter]
        string $configuredPassword,
    ): ?self {
        // Only the email is trimmed: leading or trailing whitespace in a
        // password is a legitimate part of the secret.
        $email = trim($configuredEmail);
        $hasEmail = '' !== $email;
        $hasPassword = '' !== $configuredPassword;

        if ($hasEmail !== $hasPassword) {
            throw new \InvalidArgumentException('BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD must either both be set or both be empty.');
        }

        if (!$hasEmail) {
            return null;
        }

        if (strlen($email) > self::MAXIMUM_EMAIL_LENGTH || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('BOOTSTRAP_ADMIN_EMAIL must be a valid email address of at most %d characters.', self::MAXIMUM_EMAIL_LENGTH));
        }

        $passwordViolation = self::passwordViolation($configuredPassword, 'BOOTSTRAP_ADMIN_PASSWORD');
        if (null !== $passwordViolation) {
            throw new \InvalidArgumentException($passwordViolation);
        }

        return new self($email, $configuredPassword);
    }

    /**
     * The first rule the password breaks, phrased for the caller's vocabulary.
     *
     * A returned message never contains the password: the container guard prints
     * it verbatim into the boot log, and `app:admin:reset-password` prints it to
     * a terminal that keeps scrollback.
     *
     * @param string $subject how the message names the value — an environment
     *                        variable name for a deployment, "The password" for
     *                        somebody typing a command
     *
     * @return string|null null when the password is acceptable
     */
    public static function passwordViolation(
        #[\SensitiveParameter]
        string $password,
        string $subject,
    ): ?string {
        // Bytes, not characters: the deployment paths have always measured the
        // lengths this way, and switching to mb_strlen() would start rejecting
        // a password that a running installation boots with today.
        $length = strlen($password);

        if ($length < self::MINIMUM_PASSWORD_LENGTH) {
            return sprintf('%s must be at least %d characters.', $subject, self::MINIMUM_PASSWORD_LENGTH);
        }

        if ($length > self::MAXIMUM_PASSWORD_LENGTH) {
            return sprintf('%s must be at most %d characters.', $subject, self::MAXIMUM_PASSWORD_LENGTH);
        }

        if ($length < self::COMPOSITION_WAIVER_LENGTH
            && 1 !== preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)
        ) {
            return sprintf(
                '%s must contain at least one uppercase letter, one lowercase letter, and one number, or be at least %d characters long.',
                $subject,
                self::COMPOSITION_WAIVER_LENGTH,
            );
        }

        return null;
    }
}

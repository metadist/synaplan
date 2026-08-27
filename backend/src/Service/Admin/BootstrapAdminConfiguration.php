<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Security\PasswordPolicy;

/**
 * The validated first-admin bootstrap configuration.
 *
 * This class is the single authority for the BOOTSTRAP_ADMIN_EMAIL and
 * BOOTSTRAP_ADMIN_PASSWORD rules. It deliberately has no dependencies — no
 * container, no database, no Symfony kernel — so the exact same rules can be
 * decided in three places without a second implementation that could drift:
 *
 *   1. the container entrypoint, seconds after start and before the database
 *      wait, the migrations and the seeders (see require_valid_bootstrap_admin_config
 *      in _docker/backend/lib/container-runtime.sh);
 *   2. BootstrapAdminService, which performs the actual bootstrap;
 *   3. the host-side deployment preflight, through the application image.
 *
 * The password half of those rules lives in {@see PasswordPolicy}, which
 * `app:admin:reset-password` shares — the recovery path writes to the same
 * account and must not be the lenient one.
 *
 * Values are only ever validated here, never echoed: the password is a secret,
 * so no rule violation message may contain it.
 */
final readonly class BootstrapAdminConfiguration
{
    public const MAXIMUM_EMAIL_LENGTH = 128;
    public const MINIMUM_PASSWORD_LENGTH = PasswordPolicy::MINIMUM_LENGTH;
    public const MAXIMUM_PASSWORD_LENGTH = PasswordPolicy::MAXIMUM_LENGTH;
    public const COMPOSITION_WAIVER_LENGTH = PasswordPolicy::COMPOSITION_WAIVER_LENGTH;

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

        $passwordViolation = PasswordPolicy::violation($configuredPassword, 'BOOTSTRAP_ADMIN_PASSWORD');
        if (null !== $passwordViolation) {
            throw new \InvalidArgumentException($passwordViolation);
        }

        return new self($email, $configuredPassword);
    }
}

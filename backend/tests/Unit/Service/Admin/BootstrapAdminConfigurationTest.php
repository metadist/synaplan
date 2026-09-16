<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\BootstrapAdminConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The authoritative first-admin rules, tested where they live.
 *
 * The container entrypoint and BootstrapAdminService both call this class, so
 * these cases define the behavior of the early container check as well.
 */
final class BootstrapAdminConfigurationTest extends TestCase
{
    public function testBothValuesEmptyMeansNotConfigured(): void
    {
        $this->assertNull(BootstrapAdminConfiguration::fromConfiguration('', ''));
    }

    /**
     * The deployment contract test mounts this ONE file into a bare PHP image as
     * the autoload path (assert_bootstrap_contract in
     * deploy/scripts/tests/test-lifecycle.sh), so importing a sibling class turns
     * every case there into a fatal error the exit status reports as "the
     * authority could not decide". Catch that here, where the message says why,
     * instead of three minutes into a Docker-backed CI job.
     */
    public function testTheAuthorityStaysLoadableFromItsFileAlone(): void
    {
        $source = file_get_contents((string) (new \ReflectionClass(BootstrapAdminConfiguration::class))->getFileName());

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/^use\s+App\\\\/m',
            $source,
            'the bootstrap authority must not import another App class: the deployment contract test loads this file on its own'
        );
    }

    public function testWhitespaceOnlyEmailWithoutPasswordMeansNotConfigured(): void
    {
        $this->assertNull(BootstrapAdminConfiguration::fromConfiguration("  \t ", ''));
    }

    public function testRejectsEmailWithoutPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must either both be set or both be empty');

        BootstrapAdminConfiguration::fromConfiguration('admin@example.com', '');
    }

    public function testRejectsPasswordWithoutEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must either both be set or both be empty');

        BootstrapAdminConfiguration::fromConfiguration('', 'SecurePass123!');
    }

    public function testTrimsTheEmailOnly(): void
    {
        $configuration = BootstrapAdminConfiguration::fromConfiguration(' admin@example.com ', ' SecurePass123! ');

        $this->assertNotNull($configuration);
        $this->assertSame('admin@example.com', $configuration->email);
        $this->assertSame(' SecurePass123! ', $configuration->password());
    }

    /**
     * Exit code 2 of the container guard (see require_valid_bootstrap_admin_config
     * in _docker/backend/lib/container-runtime.sh) means "the authority could not
     * run" and is deliberately lenient: it warns and continues the boot. Every
     * RULE violation must therefore arrive as an InvalidArgumentException, the one
     * class that guard maps to a hard failure. A rule thrown as anything else —
     * a \LengthException, a \ValueError — would be silently downgraded to
     * warn-and-continue and crash-loop the container later on instead.
     */
    #[DataProvider('ruleViolationProvider')]
    public function testEveryRuleViolationIsAnInvalidArgumentException(string $email, string $password): void
    {
        try {
            BootstrapAdminConfiguration::fromConfiguration($email, $password);
        } catch (\Throwable $violation) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $violation);

            return;
        }

        $this->fail('Expected the configuration to be rejected by a rule.');
    }

    /**
     * One case per rule, so a rule added later without a case here is visible.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function ruleViolationProvider(): iterable
    {
        yield 'pairing: email without password' => ['admin@example.com', ''];
        yield 'pairing: password without email' => ['', 'SecurePass123!'];
        yield 'email format' => ['not-an-email', 'SecurePass123!'];
        yield 'email length' => [str_repeat('a', 64).'@'.str_repeat('b', 60).'.com', 'SecurePass123!'];
        yield 'password minimum length' => ['admin@example.com', 'Abcdef1'];
        yield 'password maximum length' => ['admin@example.com', 'Secure1'.str_repeat('x', 58)];
        yield 'password composition' => ['admin@example.com', 'abcdefgh1'];
    }

    /**
     * The whole object is handed around, so a generic dumper must not be able to
     * print the secret. #[\SensitiveParameter] only redacts stack traces; it does
     * not protect a property, which is why the password is not one.
     */
    public function testGenericOutputNeverExposesThePassword(): void
    {
        $password = 'SecurePass123!';
        $configuration = BootstrapAdminConfiguration::fromConfiguration('admin@example.com', $password);

        $this->assertNotNull($configuration);
        $this->assertSame($password, $configuration->password());

        ob_start();
        var_dump($configuration);
        var_export($configuration);
        $dumped = (string) ob_get_clean();

        $this->assertStringNotContainsString($password, $dumped);
        $this->assertStringNotContainsString($password, print_r($configuration, true));
        $this->assertStringNotContainsString($password, (string) json_encode($configuration));
    }

    /**
     * The host-side preflight in deploy/scripts/lib.sh mirrors filter_var() for
     * the addresses an operator can realistically configure. These are the forms
     * whose looser handling once let a deployment start and then crash-loop.
     */
    #[DataProvider('invalidEmailProvider')]
    public function testRejectsInvalidEmail(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BOOTSTRAP_ADMIN_EMAIL must be a valid email address of at most 128 characters.');

        BootstrapAdminConfiguration::fromConfiguration($email, 'SecurePass123!');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmailProvider(): iterable
    {
        yield 'no at sign' => ['not-an-email'];
        yield 'doubled dot in the local part' => ['a..b@example.com'];
        yield 'leading dot in the local part' => ['.admin@example.com'];
        yield 'trailing dot in the local part' => ['admin.@example.com'];
        yield 'leading hyphen in a domain label' => ['admin@-example.com'];
        yield 'trailing hyphen in a domain label' => ['admin@example-.com'];
        yield 'numeric top-level label' => ['admin@example.123'];
        yield 'local part longer than 64 characters' => [str_repeat('a', 65).'@example.com'];
        yield 'domain label longer than 63 characters' => ['admin@'.str_repeat('a', 64).'.com'];
    }

    #[DataProvider('validEmailProvider')]
    public function testAcceptsValidEmail(string $email): void
    {
        $configuration = BootstrapAdminConfiguration::fromConfiguration($email, 'SecurePass123!');

        $this->assertNotNull($configuration);
        $this->assertSame($email, $configuration->email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validEmailProvider(): iterable
    {
        yield 'plain address' => ['admin@example.com'];
        yield 'plus addressing' => ['admin+tag@example.com'];
        yield 'subdomains' => ['admin@sub.example.co.uk'];
        yield 'hyphen inside a domain label' => ['admin@ex--ample.com'];
        yield 'digits at the start of a domain label' => ['admin@1example.com'];
        yield 'local part at the 64-character limit' => [str_repeat('a', 64).'@example.com'];
        yield 'exactly at the maximum length' => [str_repeat('a', 64).'@'.str_repeat('b', 59).'.com'];
    }

    public function testAcceptsEmailAtTheMaximumLength(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 59).'.com';

        $this->assertSame(BootstrapAdminConfiguration::MAXIMUM_EMAIL_LENGTH, strlen($email));
        $this->assertNotNull(BootstrapAdminConfiguration::fromConfiguration($email, 'SecurePass123!'));
    }

    public function testRejectsEmailOneCharacterAboveTheMaximumLength(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 60).'.com';

        $this->assertSame(BootstrapAdminConfiguration::MAXIMUM_EMAIL_LENGTH + 1, strlen($email));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 128 characters');

        BootstrapAdminConfiguration::fromConfiguration($email, 'SecurePass123!');
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BOOTSTRAP_ADMIN_PASSWORD must be at least 8 characters.');

        BootstrapAdminConfiguration::fromConfiguration('admin@example.com', 'Abcdef1');
    }

    public function testAcceptsPasswordAtTheMinimumLength(): void
    {
        $this->assertNotNull(BootstrapAdminConfiguration::fromConfiguration('admin@example.com', 'Abcdefg1'));
    }

    public function testRejectsOverlongPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BOOTSTRAP_ADMIN_PASSWORD must be at most 64 characters.');

        BootstrapAdminConfiguration::fromConfiguration('admin@example.com', 'Secure1'.str_repeat('x', 58));
    }

    public function testAcceptsPasswordAtTheMaximumLength(): void
    {
        $password = 'Secure1'.str_repeat('x', 57);

        $this->assertSame(BootstrapAdminConfiguration::MAXIMUM_PASSWORD_LENGTH, strlen($password));
        $this->assertNotNull(BootstrapAdminConfiguration::fromConfiguration('admin@example.com', $password));
    }

    #[DataProvider('incompleteCompositionProvider')]
    public function testRejectsIncompleteCompositionBelowTheWaiverLength(string $password): void
    {
        $this->assertLessThan(BootstrapAdminConfiguration::COMPOSITION_WAIVER_LENGTH, strlen($password));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one uppercase letter, one lowercase letter, and one number, or be at least 16 characters long');

        BootstrapAdminConfiguration::fromConfiguration('admin@example.com', $password);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteCompositionProvider(): iterable
    {
        yield 'no uppercase letter' => ['abcdefgh1'];
        yield 'no lowercase letter' => ['ABCDEFGH1'];
        yield 'no number' => ['Abcdefghij'];
        yield 'one character below the waiver length' => ['Ab'.str_repeat('c', 13)];
    }

    /**
     * The waiver boundary is `<`, not `<=`: a 16-character password needs no
     * character classes at all. Managed platforms inject exactly such values.
     */
    #[DataProvider('waivedCompositionProvider')]
    public function testWaivesCompositionAtTheWaiverLength(string $password): void
    {
        $this->assertGreaterThanOrEqual(BootstrapAdminConfiguration::COMPOSITION_WAIVER_LENGTH, strlen($password));
        $this->assertNotNull(BootstrapAdminConfiguration::fromConfiguration('admin@example.com', $password));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waivedCompositionProvider(): iterable
    {
        yield 'no digit at exactly the waiver length' => ['Ab'.str_repeat('c', 14)];
        yield 'all lowercase at exactly the waiver length' => [str_repeat('a', 16)];
        yield 'generated managed-platform password without a digit' => ['QWZFaxYB-gtYh-AXqFbcde'];
    }

    /**
     * The early container check prints these messages verbatim, so a message
     * that quoted the configured value would leak the secret into the log.
     */
    #[DataProvider('rejectedPasswordProvider')]
    public function testRuleViolationsNeverContainThePassword(string $password): void
    {
        try {
            BootstrapAdminConfiguration::fromConfiguration('admin@example.com', $password);
            $this->fail(sprintf('Expected the password of %d characters to be rejected.', strlen($password)));
        } catch (\InvalidArgumentException $violation) {
            $this->assertStringNotContainsString($password, $violation->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedPasswordProvider(): iterable
    {
        yield 'too short' => ['Sh0rt'];
        yield 'too long' => ['Secure1'.str_repeat('x', 58)];
        yield 'incomplete composition' => ['lowercase-only'];
    }
}

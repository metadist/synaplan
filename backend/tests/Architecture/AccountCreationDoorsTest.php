<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Service\Auth\RegistrationConfig;
use App\Service\OidcUserService;
use PHPUnit\Framework\TestCase;

/**
 * Every `new User()` in src/ is a known door.
 *
 * Self-sign-up goes through UserLifecycleService. Directory provisioning,
 * channel identities, fixtures and the login timing dummy stay listed here
 * and do not read REGISTRATION_ENABLED. An unlisted file fails.
 */
final class AccountCreationDoorsTest extends TestCase
{
    /** @var array<string, string> */
    private const DOORS = [
        'src/Command/SeedSystemAgentsCommand.php' => 'operator',
        'src/Controller/AuthController.php' => 'timing-dummy',
        'src/DataFixtures/UserFixtures.php' => 'operator',
        'src/Service/Chat/GuestSessionService.php' => 'channel',
        'src/Service/Email/EmailChatService.php' => 'channel',
        'src/Service/Iam/UserLifecycleService.php' => 'self-signup',
        'src/Service/OidcUserService.php' => 'directory',
    ];

    public function testEveryUserConstructorIsAKnownDoor(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src'));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents) || !str_contains($contents, 'new User()')) {
                continue;
            }
            $found[] = 'src/'.ltrim(substr($file->getPathname(), strlen($root.'/src')), '/');
        }

        sort($found);
        self::assertSame(array_keys(self::DOORS), $found);
    }

    public function testDirectoryProvisioningDoesNotReadTheRegistrationSwitch(): void
    {
        $constructor = (new \ReflectionClass(OidcUserService::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType ? $parameter->getType()->getName() : null,
            $constructor->getParameters(),
        );

        self::assertNotContains(RegistrationConfig::class, $types);
    }
}

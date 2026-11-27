<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setup;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Iam\UserLifecycleService;
use App\Service\Plugin\DefaultUserPluginProvisioner;
use App\Service\Setup\FirstAdminCreator;
use App\Service\Setup\FreshInstallDefaults;
use App\Service\Setup\SetupTokenService;
use App\Service\Setup\WizardFirstAdmin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class WizardFirstAdminTest extends TestCase
{
    public function testCreatingTheFirstAdminInstallsBundledPlugins(): void
    {
        $created = (new User())->setMail('admin@example.com');

        $lifecycle = $this->createMock(UserLifecycleService::class);
        $lifecycle->expects($this->once())
            ->method('createUser')
            ->willReturn($created);

        $plugins = $this->createMock(DefaultUserPluginProvisioner::class);
        $plugins->expects($this->once())->method('provisionNewUser')->with($created);

        $tokens = $this->createMock(SetupTokenService::class);
        $tokens->expects($this->once())->method('matches')->with('TOKEN')->willReturn(true);
        $tokens->expects($this->once())->method('clear');

        $users = $this->createStub(UserRepository::class);
        $users->method('countAll')->willReturn(0);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturn($lock);

        $wizard = new WizardFirstAdmin(
            $tokens,
            $locks,
            $users,
            new FirstAdminCreator(
                $lifecycle,
                new FreshInstallDefaults($this->createStub(ConfigRepository::class)),
                $plugins,
            ),
        );

        $user = $wizard->create('admin@example.com', 'SecurePass123!', 'TOKEN');

        self::assertSame($created, $user);
    }
}

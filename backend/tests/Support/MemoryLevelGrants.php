<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\AuditLogEntryRepository;
use App\Repository\UserLevelGrantRepository;
use App\Service\Iam\LegacyLevelGrants;
use App\Service\Iam\LevelGrantService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grants kept on the user object. The test's entity manager is a stub, so
 * the account is never managed and nothing hits the database.
 */
trait MemoryLevelGrants
{
    private function levelGrants(string $oidcAdminRoles = 'admin,realm-admin,synaplan-admin,administrator'): LevelGrantService
    {
        return new LevelGrantService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserLevelGrantRepository::class),
            new LegacyLevelGrants($this->createStub(AuditLogEntryRepository::class), $oidcAdminRoles),
        );
    }
}

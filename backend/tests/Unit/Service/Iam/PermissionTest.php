<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Service\Iam\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testImpliesOrder(): void
    {
        self::assertTrue(Permission::Manage->implies(Permission::Edit));
        self::assertTrue(Permission::Edit->implies(Permission::Use));
        self::assertTrue(Permission::Use->implies(Permission::Read));
        self::assertFalse(Permission::Read->implies(Permission::Use));
        self::assertFalse(Permission::Use->implies(Permission::Manage));
    }
}
